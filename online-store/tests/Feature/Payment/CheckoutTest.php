<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Livewire\Checkout\CheckoutPage;
use App\Livewire\Checkout\OrderConfirmation;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Stripe\StripeClient;

/*
 * The full cycle: cart → checkout → order → payment → intent → confirmation.
 * §37 criteria 6, 7 and 8.
 *
 * Stripe is faked; StripePaymentTest covers the Action's own arithmetic and
 * StripeWebhookSecurityTest covers the endpoint. What these prove is the
 * *wiring* — that the storefront reaches the right Actions in the right
 * order, that the total charged is the one the server computed, and that a
 * failed step leaves nothing half-written.
 */

beforeEach(function (): void {
    // Route-level tests render the full layout, which calls @vite. CI has
    // neither a dev server nor a built manifest — see troubleshooting.md,
    // "A Feature test passes locally and fails in CI with
    // ViteManifestNotFoundException".
    $buildPath = public_path('build');

    if (! File::exists($buildPath.'/manifest.json')) {
        File::ensureDirectoryExists($buildPath);
        File::put($buildPath.'/manifest.json', json_encode([
            'resources/css/app.css' => ['file' => 'assets/app.css', 'src' => 'resources/css/app.css'],
            'resources/js/app.js' => ['file' => 'assets/app.js', 'src' => 'resources/js/app.js'],
        ]));

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($buildPath));
    }
});

// FakeCourierGateway, swapFakeCourier() and checkoutCarrier() live in
// tests/Pest.php — CreateOrderTest and CalculateDeliveryPriceTest need them
// too. Checkout must never reach Econt or Speedy over the network.
beforeEach(fn () => swapFakeCourier());

/**
 * A cart with one line, bound to whoever the test is acting as.
 *
 * `ResolveCurrentCart` finds a guest's cart by `Session::getId()` and a
 * customer's by `user_id`, so a cart created any other way is invisible to
 * the component under test — it would quietly open a second, empty one and
 * every assertion would be about the wrong cart.
 */
function checkoutCart(int $quantity = 2, string $price = '50.00', int $stock = 10, ?User $owner = null): Cart
{
    $variation = cartVariation(stock: $stock, product: [
        'regular_price' => $price,
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
        'vat_rate' => 20.00,
        'min_order_quantity' => 1,
        'is_available' => true,
    ]);

    $cart = Cart::factory()->create([
        'user_id' => $owner?->getKey(),
        'session_id' => $owner === null ? Session::getId() : null,
        'coupon_id' => null,
        'expires_at' => null,
    ]);

    $cart->cartItems()->create([
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return $cart;
}

/** An empty cart bound the same way. */
function emptyCheckoutCart(?User $owner = null): Cart
{
    return Cart::factory()->create([
        'user_id' => $owner?->getKey(),
        'session_id' => $owner === null ? Session::getId() : null,
        'coupon_id' => null,
        'expires_at' => null,
    ]);
}

/** Fills every required field with something valid. */
function fillCheckout(mixed $component, array $overrides = []): mixed
{
    $defaults = [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        'phone' => '+359888123456',
        'delivery_type' => 'address',
        'carrier_id' => checkoutCarrier()->getKey(),
        'country' => 'BG',
        'city' => 'Sofia',
        'postcode' => '1000',
        'street' => 'Vitosha 1',
        'billing_same_as_delivery' => true,
        'payment_method' => PaymentMethod::CashOnDelivery->value,
    ];

    foreach (array_merge($defaults, $overrides) as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

function fakeStripeIntents(string $intentId = 'pi_checkout_1'): void
{
    $intents = Mockery::mock();
    $intents->shouldReceive('create')->andReturn((object) ['id' => $intentId]);
    $intents->shouldReceive('retrieve')->andReturn((object) [
        'id' => $intentId,
        'client_secret' => $intentId.'_secret_abc',
    ]);

    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('getService')->with('paymentIntents')->andReturn($intents);

    app()->instance(StripeClient::class, $client);
}

/*
 * ── The page itself ─────────────────────────────────────────────────────
 */

it('is reachable by a guest and by a signed-in customer', function (): void {
    $this->get('/checkout')->assertOk();

    $this->actingAs(User::factory()->create())->get('/checkout')->assertOk();
});

it('prefills a signed-in customer without locking the fields', function (): void {
    $user = User::factory()->create([
        'email' => 'known@example.test',
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
    ]);

    Livewire::actingAs($user)
        ->test(CheckoutPage::class)
        ->assertSet('email', 'known@example.test')
        ->assertSet('first_name', 'Grace')
        // Still editable: whatever is submitted is what gets snapshotted.
        ->set('email', 'other@example.test')
        ->assertSet('email', 'other@example.test');
});

/*
 * ── §37 #6: a guest can complete checkout ───────────────────────────────
 */

it('lets a guest place a cash-on-delivery order', function (): void {
    $cart = checkoutCart(quantity: 2, price: '50.00');

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder')->assertHasNoErrors();

    $order = Order::query()->latest('id')->first();

    expect($order)->not->toBeNull()
        // A guest order carries no user_id — CreateOrder records the actor it
        // is given and never infers one from a matching email.
        ->and($order->user_id)->toBeNull()
        ->and($order->email)->toBe('ada@example.test')
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->payment->method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->payment->status)->toBe(PaymentStatus::Pending)
        // COD skips Stripe entirely.
        ->and($order->payment->stripe_payment_intent_id)->toBeNull();
});

/*
 * ── §37 #7: a registered customer can complete checkout ─────────────────
 */

it('records the user on an order placed by a signed-in customer', function (): void {
    $user = User::factory()->create();
    $cart = checkoutCart(owner: $user);

    $component = Livewire::actingAs($user)->test(CheckoutPage::class);

    fillCheckout($component)->call('placeOrder')->assertHasNoErrors();

    expect(Order::query()->latest('id')->first()->user_id)->toBe($user->getKey());
});

/*
 * ── §37 #8: the total is computed server-side ───────────────────────────
 */

it('charges the server-computed total, not anything the browser could send', function (): void {
    // 2 × 50.00 = 100.00, plus the fake courier's 5.00 quote (cod_fee
    // pinned to zero by checkoutCarrier()) = 105.00.
    $cart = checkoutCart(quantity: 2, price: '50.00');

    $component = Livewire::test(CheckoutPage::class);

    fillCheckout($component)->call('placeOrder')->assertHasNoErrors();

    $order = Order::query()->latest('id')->first();

    expect((string) $order->total_amount)->toBe('105.00')
        ->and((string) $order->shipping_amount)->toBe('5.00')
        // The payment copies the order's own figure, not the caller's.
        ->and((string) $order->payment->amount)->toBe('105.00')
        // VAT is extracted from the gross *item* total (prices stored
        // gross) rather than added on top — 20% of a 100.00 gross line is
        // ~16.67, not 20.00. shipping_amount carries no VAT of its own here
        // (§37's delivery-VAT treatment is not part of this slice).
        //
        // 16.66 and not 16.67 because Money::percentageOf() truncates its
        // double-scale intermediate (bcadd does not round). The true value
        // is 16.6667. Asserted as-is so this test documents what the code
        // does rather than what it ought to; the rounding question is a
        // separate decision about shared money code, not a Stripe one.
        ->and((string) $order->vat_amount)->toBe('16.66');
});

it('has no price property a client could set, and refuses one that is invented', function (): void {
    $cart = checkoutCart(quantity: 2, price: '50.00');

    $component = Livewire::test(CheckoutPage::class);

    // Livewire refuses to bind a property the component does not declare, so
    // an injected `total` never reaches the server state at all — a stronger
    // guarantee than "it would have been ignored". §37 #8 rests on the
    // component having no price field in the first place, not on validating
    // one away.
    expect(fn () => $component->set('total', '0.01'))
        ->toThrow(PublicPropertyNotFoundException::class);

    // And the order still prices itself from the cart (+ the fake courier's
    // 5.00 delivery quote).
    fillCheckout($component)->call('placeOrder')->assertHasNoErrors();

    expect((string) Order::query()->latest('id')->first()->total_amount)->toBe('105.00');
});

/*
 * ── §37 #9: the Stripe leg ──────────────────────────────────────────────
 */

it('creates a PaymentIntent and hands the browser a client secret for a card order', function (): void {
    fakeStripeIntents('pi_checkout_9');
    $cart = checkoutCart(quantity: 1, price: '25.00');

    $component = Livewire::test(CheckoutPage::class);

    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])
        ->call('placeOrder')
        ->assertHasNoErrors()
        // Stays on the page for the card step rather than redirecting.
        ->assertSet('clientSecret', 'pi_checkout_9_secret_abc');

    $payment = Order::query()->latest('id')->first()->payment;

    expect($payment->method)->toBe(PaymentMethod::Stripe)
        ->and($payment->stripe_payment_intent_id)->toBe('pi_checkout_9')
        // Still Pending: only the webhook marks it Paid. A browser redirect
        // is not proof of payment.
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('reserves stock when the order is placed, before payment confirms', function (): void {
    fakeStripeIntents();
    $cart = checkoutCart(quantity: 3, price: '10.00', stock: 10);

    $variation = $cart->cartItems()->first()->productVariation;

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    // Reserved at order time, not at payment time — otherwise two customers
    // could both reach the card step for the last unit.
    expect($variation->inventory->fresh()->reserved_quantity)->toBe(3);
});

/*
 * ── Refusals ────────────────────────────────────────────────────────────
 */

it('refuses to place an order from an empty cart', function (): void {
    emptyCheckoutCart();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder')->assertHasErrors('email');

    expect(Order::count())->toBe(0);
});

it('surfaces an out-of-stock refusal on the form rather than as a 500', function (): void {
    // More in the cart than the shelf holds — someone else took it between
    // the cart page and here.
    $cart = checkoutCart(quantity: 5, price: '10.00', stock: 2);

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder')->assertHasErrors('email');

    expect(Order::count())->toBe(0);
});

it('requires a street for an address delivery and an office for an office delivery', function (): void {
    $cart = checkoutCart();

    $component = Livewire::test(CheckoutPage::class);

    fillCheckout($component, ['delivery_type' => 'address', 'street' => ''])
        ->call('placeOrder')
        ->assertHasErrors('street');

    fillCheckout($component, ['delivery_type' => 'office', 'street' => '', 'courier_office_code' => ''])
        ->call('placeOrder')
        ->assertHasErrors('courier_office_code');
});

/*
 * ── Carrier and office selection ────────────────────────────────────────
 */

it('requires a carrier to be selected', function (): void {
    $cart = checkoutCart();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => null])->call('placeOrder')->assertHasErrors('carrier_id');

    expect(Order::count())->toBe(0);
});

it('refuses an inactive carrier even if its id is submitted directly', function (): void {
    $cart = checkoutCart();
    $inactive = checkoutCarrier(['is_active' => false]);

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $inactive->getKey()])
        ->call('placeOrder')
        ->assertHasErrors('carrier_id');

    expect(Order::count())->toBe(0);
});

it('places an office delivery only once an office is picked through selectOffice()', function (): void {
    $cart = checkoutCart();
    $carrier = checkoutCarrier();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $carrier->getKey(), 'delivery_type' => 'office', 'street' => '']);

    $component->call('selectOffice', 'OFF1')->call('placeOrder')->assertHasNoErrors();

    $order = Order::query()->latest('id')->first();
    $delivery = $order->orderAddresses()->where('type', 'delivery')->first();

    expect($delivery->courier_office_code)->toBe('OFF1')
        ->and($delivery->courier_office_name)->toBe('Test Office 1')
        ->and($order->carrier_id)->toBe($carrier->getKey());
});

it('refuses an office code that was never resolved from the carrier\'s own list', function (): void {
    // The browser can still submit any string as the property value even
    // though the input is no longer free text — placeOrder() re-resolves it
    // against offices() rather than trusting the property.
    $cart = checkoutCart();
    $carrier = checkoutCarrier();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $carrier->getKey(), 'delivery_type' => 'office', 'street' => '']);
    $component->set('courier_office_code', 'NOT-A-REAL-OFFICE');

    $component->call('placeOrder')->assertHasErrors('courier_office_code');

    expect(Order::count())->toBe(0);
});

it('keeps the office list untouched by a field the office selection does not depend on', function (): void {
    // Reported bug: toggling billing_same_as_delivery made the office list
    // disappear. updated() only clears offices()/courier_office_code for
    // carrier_id/city/postcode/delivery_type — billing_same_as_delivery is
    // not one of them, so the office list and any already-picked office must
    // survive it untouched.
    $cart = checkoutCart();
    $carrier = checkoutCarrier();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $carrier->getKey(), 'delivery_type' => 'office', 'street' => '']);
    $component->call('selectOffice', 'OFF1');

    expect($component->get('courier_office_code'))->toBe('OFF1')
        ->and($component->instance()->offices())->not->toBeEmpty();

    $component->set('billing_same_as_delivery', false);

    expect($component->get('courier_office_code'))->toBe('OFF1')
        ->and($component->instance()->offices())->not->toBeEmpty()
        ->and($component->instance()->courierUnavailable())->toBeFalse();

    $component->set('billing_same_as_delivery', true);

    expect($component->get('courier_office_code'))->toBe('OFF1')
        ->and($component->instance()->offices())->not->toBeEmpty();
});

it('evaluates the live courier lookup once per render, not once per reader', function (): void {
    // offices() and courierUnavailable() both read the same underlying
    // lookup; Blade calls both on every render (the "unavailable" message
    // and the list are alternatives to each other). Before this was
    // memoised per-request, each reader ran its own live call, so a single
    // render on a cold cache could reach the real courier up to three times.
    $cart = checkoutCart();
    $carrier = checkoutCarrier();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $carrier->getKey(), 'delivery_type' => 'office', 'street' => '', 'city' => 'Sofia']);

    $callsBeforeOneMoreRender = fakeCourier()->officesCalls;

    // Any further field triggers one more full render, with both readers
    // hitting the same already-established carrier/city.
    $component->set('office_search', 'Test');

    expect(fakeCourier()->officesCalls)->toBe($callsBeforeOneMoreRender + 1);
});

it('keeps showing the last successful office list when a later render\'s courier call fails transiently', function (): void {
    // Reported symptom: the office list would render, then vanish, on a
    // render triggered by a field the office selection does not depend on
    // (billing_same_as_delivery). Econt's demo host is public and shared —
    // any render can be the one whose live call happens to fail — so a
    // list already shown to the customer must not disappear because of it.
    $cart = checkoutCart();
    $carrier = checkoutCarrier();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $carrier->getKey(), 'delivery_type' => 'office', 'street' => '']);
    $component->set('city', 'Sofia');

    expect($component->instance()->offices())->not->toBeEmpty()
        ->and($component->instance()->courierUnavailable())->toBeFalse();

    fakeCourier()->failNextOfficesCall = true;
    $component->set('billing_same_as_delivery', false);

    expect($component->instance()->offices())->not->toBeEmpty()
        ->and($component->instance()->courierUnavailable())->toBeFalse();
});

it('drops the stale office list once the city actually changes, even after a transient failure fallback', function (): void {
    $cart = checkoutCart();
    $carrier = checkoutCarrier();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['carrier_id' => $carrier->getKey(), 'delivery_type' => 'office', 'street' => '']);
    $component->set('city', 'Sofia');

    expect($component->instance()->offices())->not->toBeEmpty();

    fakeCourier()->fakeOffices = collect();
    $component->set('city', 'Plovdiv');

    // A genuinely empty result for the new city must not be masked by the
    // old city's list — the fallback only covers a *failed* call, not an
    // honestly empty one.
    expect($component->instance()->offices())->toBeEmpty()
        ->and($component->instance()->courierUnavailable())->toBeFalse();
});

it('adds the carrier\'s cash-on-delivery fee to the delivery price only for COD orders', function (): void {
    $cart = checkoutCart(quantity: 1, price: '10.00');
    $carrier = checkoutCarrier(['cod_fee' => '1.50']);
    fakeCourier()->fakeQuoteAmount = '5.00';

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, [
        'carrier_id' => $carrier->getKey(),
        'payment_method' => PaymentMethod::CashOnDelivery->value,
    ])->call('placeOrder')->assertHasNoErrors();

    // 5.00 quote + 1.50 COD handling fee.
    expect((string) Order::query()->latest('id')->first()->shipping_amount)->toBe('6.50');
});

it('leaves nothing behind when the order fails', function (): void {
    $cart = checkoutCart(quantity: 5, price: '10.00', stock: 1);

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    // The whole checkout is one transaction: no order, and so no orphaned
    // payment row reserving nothing.
    expect(Order::count())->toBe(0)
        ->and(Payment::count())->toBe(0);
});

/*
 * ── Confirmation, and the enumeration guard ─────────────────────────────
 */

it('shows the confirmation to the guest who just placed the order', function (): void {
    $cart = checkoutCart();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder');

    $order = Order::query()->latest('id')->first();

    session([OrderConfirmation::SESSION_KEY => $order->getKey()]);

    Livewire::test(OrderConfirmation::class, ['order' => $order->getKey()])
        ->assertOk()
        ->assertSee($order->serial_number);
});

it('404s for a stranger guessing an order id', function (): void {
    $cart = checkoutCart();
    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder');

    $order = Order::query()->latest('id')->first();

    // A different visitor: the session that placed the order is exactly the
    // claim being tested, so it has to go. Without this the "stranger" is
    // the buyer and the test passes for the wrong reason.
    session()->flush();

    // No session claim, not the owner. Serial numbers are sequential, so a
    // bare findOrFail here would enumerate every customer's address.
    $this->get('/checkout/confirmation/'.$order->getKey())->assertNotFound();
});

it('404s for a signed-in customer looking at someone else\'s order', function (): void {
    $cart = checkoutCart();
    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder');

    $order = Order::query()->latest('id')->first();

    session()->flush();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('/checkout/confirmation/'.$order->getKey())
        ->assertNotFound();
});

it('shows a registered customer their own order without a session claim', function (): void {
    $user = User::factory()->create();
    $cart = checkoutCart(owner: $user);

    $component = Livewire::actingAs($user)->test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder');

    $order = Order::query()->latest('id')->first();

    // Owned, so reachable without the just-placed session key.
    Livewire::actingAs($user)
        ->test(OrderConfirmation::class, ['order' => $order->getKey()])
        ->assertOk();
});

/*
 * ── The cart's own link ─────────────────────────────────────────────────
 */

it('offers a working checkout link from the cart, not a dead button', function (): void {
    // Owned by a user rather than a session: a plain HTTP request gets its
    // own session id, so a guest cart created before the request would be
    // invisible and the page would render its empty branch.
    $user = User::factory()->create();
    checkoutCart(owner: $user);

    $this->actingAs($user)
        ->get('/cart')
        ->assertOk()
        ->assertSee(route('checkout'), escape: false)
        // The button used to be disabled with this label next to it.
        ->assertDontSee('Checkout is not built yet');
});
