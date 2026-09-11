<?php

declare(strict_types=1);

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Livewire\Checkout\CheckoutPage;
use App\Livewire\Checkout\OrderConfirmation;
use App\Mail\OrderPlaced;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use App\Support\Resolvers\ResolveCurrentCart;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

// Route-level tests render the full layout, which calls @vite; the fake
// manifest this needs is a global beforeEach in tests/Pest.php now, not
// duplicated per file — see its own comment for why that stopped being
// safe once more than one route-level test file existed.

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

it('starts a signed-in customer on their default shipping address', function (): void {
    $user = User::factory()->create();
    $user->addresses()->create([
        'label' => 'Home',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'phone' => '0888123456',
        'country' => 'BG',
        'city' => 'Plovdiv',
        'postcode' => '4000',
        'street' => 'Ulitsa 1',
        'is_default_shipping' => true,
        'is_default_billing' => false,
    ]);

    Livewire::actingAs($user)
        ->test(CheckoutPage::class)
        ->assertSet('selected_address_id', $user->addresses()->first()->id)
        ->assertSet('city', 'Plovdiv')
        ->assertSet('postcode', '4000')
        ->assertSet('street', 'Ulitsa 1')
        ->assertSet('delivery_type', 'address');
});

it('fills the delivery fields when a saved address is picked, and only from the customers own', function (): void {
    $user = User::factory()->create();
    $mine = $user->addresses()->create([
        'first_name' => 'A', 'last_name' => 'B', 'phone' => '1', 'country' => 'BG',
        'city' => 'Varna', 'postcode' => '9000', 'street' => 'Sea St 3',
        'is_default_shipping' => false, 'is_default_billing' => false,
    ]);
    $someoneElse = User::factory()->create()->addresses()->create([
        'first_name' => 'X', 'last_name' => 'Y', 'phone' => '2', 'country' => 'BG',
        'city' => 'Ruse', 'postcode' => '7000', 'street' => 'Not Yours 1',
        'is_default_shipping' => false, 'is_default_billing' => false,
    ]);

    $component = Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('selected_address_id', $mine->id)
        ->assertSet('city', 'Varna')
        ->assertSet('street', 'Sea St 3');

    // An id the customer does not own resolves to nothing.
    $component->set('selected_address_id', $someoneElse->id)
        ->assertSet('selected_address_id', null)
        ->assertSet('city', 'Varna');
});

it('drops the saved-address selection once a delivery field is hand-edited', function (): void {
    $user = User::factory()->create();
    $address = $user->addresses()->create([
        'first_name' => 'A', 'last_name' => 'B', 'phone' => '1', 'country' => 'BG',
        'city' => 'Sofia', 'postcode' => '1000', 'street' => 'Main 1',
        'is_default_shipping' => true, 'is_default_billing' => false,
    ]);

    Livewire::actingAs($user)->test(CheckoutPage::class)
        ->assertSet('selected_address_id', $address->id)
        ->set('city', 'Burgas')
        ->assertSet('selected_address_id', null);
});

/*
 * ── §37 #6: a guest can complete checkout ───────────────────────────────
 */

it('lets a guest place a cash-on-delivery order', function (): void {
    Mail::fake();
    $cart = checkoutCart(quantity: 2, price: '50.00');

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component)->call('placeOrder')->assertHasNoErrors();

    // The durable-medium order confirmation (CRD Art. 8(7), ADR-0019) is
    // queued to the address the order carries.
    Mail::assertQueued(
        OrderPlaced::class,
        fn (OrderPlaced $mail): bool => $mail->hasTo('ada@example.test')
            && $mail->order->is(Order::query()->latest('id')->first()),
    );

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
        // 16.67, half-up rounded from the true 16.6666...: Money's
        // percentageOf() rounds half-up at its final digit rather than
        // truncating, same as ordinary commercial rounding.
        ->and((string) $order->vat_amount)->toBe('16.67');
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
 * ── SEC-002: order serial numbers are not enumerable via CheckoutPage ────
 *
 * The payment step renders the placed order's serial number. It once did so
 * from a bare, unscoped Order::find() on `orderId` — a client-writable
 * public property — so a visitor could point it at another customer's order
 * and read that serial
 * (reference/testing/security-testing/sec-001-to-004.md, SEC-002). Two
 * mechanisms fix it, each with a test that goes red if removed:
 *   1. `order()` scopes to owner-or-session-claim, never a bare find().
 *   2. #[Locked] on `orderId` and `clientSecret` refuses the client write.
 */

it('locks orderId and clientSecret against client tampering', function (): void {
    checkoutCart(quantity: 2, price: '50.00');

    $component = Livewire::test(CheckoutPage::class);

    // The exploit's own move — set orderId to a foreign order and a forged
    // clientSecret to open the render branch. #[Locked] refuses both. Remove
    // either attribute and the matching set() stops throwing: red.
    expect(fn () => $component->set('orderId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('clientSecret', 'pi_forged'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not disclose an order the visitor neither owns nor placed this session', function (): void {
    // A victim order owned by another customer.
    $victim = User::factory()->create();
    $victimOrder = Order::factory()->for($victim)->create();

    // A guest visitor with no claim to it: order() must return null, so the
    // serial never reaches the page. With the scope removed, order() falls
    // back to Order::find() and returns the victim's row — red.
    $component = new CheckoutPage;
    $component->orderId = $victimOrder->getKey();

    expect($component->order())->toBeNull();
});

it('shows a signed-in customer their own placed order on the payment step', function (): void {
    // The legitimate owner path still works — the fix must not break it.
    $user = User::factory()->create();
    $ownOrder = Order::factory()->for($user)->create();

    $component = new CheckoutPage;
    $component->orderId = $ownOrder->getKey();

    Livewire::actingAs($user);

    expect($component->order()?->getKey())->toBe($ownOrder->getKey());
});

it('shows a guest the order they just placed via the session claim', function (): void {
    // The legitimate guest path: the session claim CheckoutPage writes in
    // placeOrder() (before setting orderId) is what lets order() resolve it.
    $order = Order::factory()->create(['user_id' => null]);
    session([OrderConfirmation::SESSION_KEY => $order->getKey()]);

    $component = new CheckoutPage;
    $component->orderId = $order->getKey();

    expect($component->order()?->getKey())->toBe($order->getKey());
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

    $order = Order::query()->latest('id')->first();
    $payment = $order->payment;

    expect($payment->method)->toBe(PaymentMethod::Stripe)
        ->and($payment->stripe_payment_intent_id)->toBe('pi_checkout_9')
        // Still Pending: only the webhook marks it Paid. A browser redirect
        // is not proof of payment.
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        // ADR-0022 decision 1: the card order moves off New the moment it
        // has somewhere to be paid. This is what makes an abandoned
        // checkout findable by ExpireUnpaidOrders — a COD order stays at
        // New, so without this the two are indistinguishable.
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment);
});

/*
 * ── ADR-0022: the abandonment bug ───────────────────────────────────────
 *
 * Reaching the Stripe payment step is not buying anything. Until ADR-0022
 * this queued OrderPlaced anyway, so a customer who closed the tab was told
 * "your order" for goods they never paid for. The card path's confirmation
 * now waits for payment_intent.succeeded.
 */

it('queues no confirmation email when a card order only reaches the payment step', function (): void {
    Mail::fake();
    fakeStripeIntents();
    $cart = checkoutCart(quantity: 1, price: '25.00');

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])
        ->call('placeOrder')
        ->assertHasNoErrors();

    // The order exists and holds its stock; nothing has been confirmed to
    // the customer, because nothing has been paid.
    Mail::assertNotQueued(OrderPlaced::class);
    expect(Order::query()->latest('id')->first()->status)->toBe(OrderStatus::AwaitingPayment);
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

/*
 * ── The email the confirmation is sent to ───────────────────────────────
 *
 * `email:rfc` is a syntax check, not a deliverability one. What it does
 * catch is worth pinning, because for a card order this address is the
 * customer's only record of a contract they actually paid for — the
 * confirmation is sent once payment lands (ADR-0022) and there is no
 * second chance to correct it.
 *
 * What it deliberately does **not** check is whether the domain exists or
 * accepts mail. See "Not done" in `explanation/transactional-email.md`.
 */

it('refuses a malformed email before any order exists', function (): void {
    $cart = checkoutCart();

    $component = Livewire::test(CheckoutPage::class);

    fillCheckout($component, ['email' => 'not-an-address'])
        ->call('placeOrder')
        ->assertHasErrors('email');

    // Refused at validation, so nothing was written and no stock was held.
    expect(Order::count())->toBe(0);
})->with([
    'no at sign' => 'not-an-address',
    'no domain' => 'ada@',
    'no local part' => '@example.test',
    'spaces' => 'ada lovelace@example.test',
    'empty' => '',
]);

it('sends the confirmation to the address on the order, not the account', function (): void {
    Mail::fake();

    // A signed-in customer who types a different address at checkout: the
    // order carries what was submitted, and CreateOrder never infers an
    // actor from a matching email, so the confirmation must follow the
    // order rather than the account.
    $user = User::factory()->create(['email' => 'account@example.test']);
    checkoutCart(owner: $user);

    $component = Livewire::actingAs($user)->test(CheckoutPage::class);
    fillCheckout($component, ['email' => 'different@example.test'])
        ->call('placeOrder')
        ->assertHasNoErrors();

    Mail::assertQueued(
        OrderPlaced::class,
        fn (OrderPlaced $mail): bool => $mail->hasTo('different@example.test')
            && ! $mail->hasTo('account@example.test'),
    );
});

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
 * ── Checkout in N tabs, one session ─────────────────────────────────────
 *
 * `ResolveCurrentCart` binds a guest cart to the session, so every tab
 * shares one cart — but each tab that reaches `placeOrder` runs
 * `CreateOrder` → `ReserveStock` again. The question this answers is the
 * blunt one: does opening checkout in N tabs reserve N times the stock?
 *
 * It does not, and the mechanism is worth naming precisely, because it is
 * **not** the one the schema suggests. `UNIQUE(orders.cart_id)` and
 * `CartAlreadyCheckedOutException` are a real backstop and are never
 * reached from this path: the winning tab's `CreateOrder` consumes the
 * cart, `ResolveCurrentCart` then excludes it as spent, and the losing tabs
 * resolve to a fresh empty cart and short-circuit on `isEmpty()` before
 * `CreateOrder` is ever called. Measured live at N=4 before being written
 * down.
 */

it('reserves stock once however many tabs reach the payment step', function (): void {
    fakeStripeIntents();
    $cart = checkoutCart(quantity: 2, price: '10.00', stock: 10);
    $variation = $cart->cartItems()->first()->productVariation;

    // Four tabs, one session, one cart — four component instances is what
    // "four tabs" actually is at this layer.
    $tabs = collect(range(1, 4))->map(fn (): mixed => Livewire::test(CheckoutPage::class));

    $tabs->each(fn (mixed $tab) => fillCheckout($tab, [
        'payment_method' => PaymentMethod::Stripe->value,
    ])->call('placeOrder'));

    expect(Order::count())->toBe(1)
        // 2, not 8. The whole point of the item.
        ->and($variation->inventory->fresh()->reserved_quantity)->toBe(2)
        // One payment and so one Stripe intent — a second would be a second
        // chargeable card form for one basket.
        ->and(Payment::count())->toBe(1);
});

it('refuses the losing tabs with a form error rather than a 500', function (): void {
    fakeStripeIntents();
    checkoutCart(quantity: 2, price: '10.00', stock: 10);

    $winner = Livewire::test(CheckoutPage::class);
    $loser = Livewire::test(CheckoutPage::class);

    fillCheckout($winner, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    // The loser's cart is spent and therefore invisible to
    // ResolveCurrentCart, so it sees an empty basket. Whatever the wording,
    // the requirement is that it is a message on the form and not an
    // uncaught exception.
    fillCheckout($loser, ['payment_method' => PaymentMethod::Stripe->value])
        ->call('placeOrder')
        ->assertHasErrors('email');

    expect($loser->get('clientSecret'))->toBeNull()
        ->and(Order::count())->toBe(1);
});

/*
 * ── Cancel, at both checkout sub-states (ADR-0022) ──────────────────────
 *
 * The deliberate counterpart to the abandonment sweep: the customer says
 * they are done instead of closing the tab, and gets an immediate release
 * plus their basket back rather than waiting out the unpaid-order TTL.
 */

it('cancels at the details step without having written anything', function (): void {
    $cart = checkoutCart();

    Livewire::test(CheckoutPage::class)
        ->call('cancelCheckout')
        ->assertRedirect(route('cart'));

    // placeOrder is the only thing that creates an order, and it never ran.
    expect(Order::count())->toBe(0)
        ->and($cart->fresh()->cartItems()->count())->toBe(1);
});

it('cancels at the payment step, releasing the stock it was holding', function (): void {
    fakeStripeIntents();
    $cart = checkoutCart(quantity: 3, price: '10.00', stock: 10);
    $variation = $cart->cartItems()->first()->productVariation;

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    expect($variation->inventory->fresh()->reserved_quantity)->toBe(3);

    $component->call('cancelPayment')->assertRedirect(route('cart'));

    $order = Order::query()->latest('id')->first();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        // TransitionOrderStatus owns the release (ADR-0011); cancelPayment
        // composes no ReleaseStock call of its own.
        ->and($variation->inventory->fresh()->reserved_quantity)->toBe(0);
});

it('gives the customer their basket back after cancelling the payment', function (): void {
    fakeStripeIntents();
    $cart = checkoutCart(quantity: 2, price: '25.00');
    $variationId = $cart->cartItems()->first()->product_variation_id;

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    // CreateOrder consumed the cart, so the original is spent and
    // ResolveCurrentCart will never hand it back.
    $component->call('cancelPayment');

    $current = ResolveCurrentCart::existing();

    expect($current)->not->toBeNull()
        ->and($current->is($cart))->toBeFalse()
        ->and($current->cartItems()->sole()->product_variation_id)->toBe($variationId)
        ->and($current->cartItems()->sole()->quantity)->toBe(2);
});

it('drops the session claim on an order it cancelled', function (): void {
    fakeStripeIntents();
    checkoutCart();

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    expect(session(OrderConfirmation::SESSION_KEY))->not->toBeNull();

    $component->call('cancelPayment');

    // A confirmation page for an order that no longer stands is a
    // misleading thing to leave reachable.
    expect(session(OrderConfirmation::SESSION_KEY))->toBeNull();
});

it('refuses to cancel an order whose payment already landed', function (): void {
    fakeStripeIntents();
    $cart = checkoutCart(quantity: 2, price: '10.00', stock: 10);
    $variation = $cart->cartItems()->first()->productVariation;

    $component = Livewire::test(CheckoutPage::class);
    fillCheckout($component, ['payment_method' => PaymentMethod::Stripe->value])->call('placeOrder');

    $order = Order::query()->latest('id')->first();

    // The webhook landed while the customer was reaching for Cancel.
    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid, null);

    $component->call('cancelPayment')
        ->assertRedirect(route('checkout.confirmation', ['order' => $order->getKey()]));

    // Cancelling a paid order from a customer button would be a refund,
    // which is staff work — and would release stock that was sold.
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($variation->inventory->fresh()->reserved_quantity)->toBe(2);
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

/*
 * `{order}` is a plain route segment, not `Order $order` (the docblock on
 * `$orderId` explains why: a matching route-model-bound property name would
 * collide with Livewire's own hydration). `mount(int $order)` meant PHP
 * itself threw TypeError on anything the route sent that could not coerce
 * to int — before mount()'s own body ran, the same "hydration happens
 * before your code does" shape as the #[Url] incidents in
 * test-for-input-crashes.md, on route binding instead of query-string
 * hydration. Confirmed live: a full debug trace (container-resolution
 * stack, absolute vendor paths) to an anonymous visitor under local's
 * APP_DEBUG=true — /checkout/confirmation/abc. 404, the same outcome a
 * well-formed id nothing matches already gets, is what `is_numeric` in
 * mount() now produces instead.
 */

it('404s rather than crashes on a non-numeric order id in the URL', function (): void {
    $this->get('/checkout/confirmation/abc')->assertNotFound();
});

it('404s rather than crashes on a decimal order id in the URL', function (): void {
    $this->get('/checkout/confirmation/3.5')->assertNotFound();
});

it('404s rather than crashes on an order id too large for PHP to represent as an int', function (): void {
    $this->get('/checkout/confirmation/99999999999999999999999999999999')->assertNotFound();
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
