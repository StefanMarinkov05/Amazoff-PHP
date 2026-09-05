<?php

declare(strict_types=1);

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Livewire\Cart\CartPage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * CartPage's own contribution over the Actions and Support classes it
 * wires up: the ownership gate on every id arriving from the browser,
 * mapping an Action's refusal to a line-scoped form error and snapping the
 * quantity box back to the row's truth, touching the cart's expiry on a
 * successful write, and discount()'s bridge from CalculateCouponDiscount
 * into the page. The underlying rules — stock, minimum order quantity,
 * coupon scope matching — are AddToCartTest/UpdateCartItemQuantityTest/
 * CalculateCouponDiscountTest's job, not this file's. `write-rules/cart.md`.
 */

function cartLine(Cart $cart, ProductVariation $variation, int $quantity = 1): CartItem
{
    return $cart->cartItems()->create([
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);
}

/**
 * A cart bound to whoever the test is acting as — Pest.php's own
 * `emptyCart()` does not set `session_id`, so it is invisible to
 * `ResolveCurrentCart::forVisitor()`: the component would silently open a
 * second, empty cart of its own, and every assertion here would be about
 * the wrong one. Same trap and same fix `CheckoutTest`'s own helpers name.
 */
function visitorCart(?User $owner = null): Cart
{
    return Cart::factory()->create([
        'user_id' => $owner?->getKey(),
        'session_id' => $owner === null ? Session::getId() : null,
        'coupon_id' => null,
        'expires_at' => null,
    ]);
}

/** A coupon with `EntireOrder` defaults, cheap to override per test. */
function cartPageCoupon(array $overrides = []): Coupon
{
    return Coupon::factory()->create(array_merge([
        'code' => 'CART'.fake()->unique()->numberBetween(1000, 999999),
        'type' => CouponType::Percentage,
        'scope' => CouponScope::EntireOrder,
        'value' => '10.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => null,
        'usage_limit_per_customer' => null,
    ], $overrides));
}

// ── Ownership gate ──────────────────────────────────────────────

it('ignores an item id that belongs to someone else\'s cart', function (): void {
    visitorCart(); // the visitor's own cart, bound to this test's session

    $theirCart = emptyCart(User::factory()->create());
    $theirItem = cartLine($theirCart, cartVariation(), 2);

    Livewire::test(CartPage::class)
        ->call('remove', $theirItem->getKey())
        ->call('increment', $theirItem->getKey())
        ->call('decrement', $theirItem->getKey());

    expect($theirItem->fresh())->not->toBeNull()
        ->and($theirItem->fresh()->quantity)->toBe(2);
});

// ── Increment / decrement / remove ──────────────────────────────

it('increments an owned line by one within stock', function (): void {
    $cart = visitorCart();
    $variation = cartVariation(stock: 10);
    $item = cartLine($cart, $variation, 1);

    Livewire::test(CartPage::class)
        ->call('increment', $item->getKey())
        ->assertHasNoErrors();

    expect($item->fresh()->quantity)->toBe(2);
});

it('decrements an owned line by one', function (): void {
    $cart = visitorCart();
    $variation = cartVariation(stock: 10);
    $item = cartLine($cart, $variation, 3);

    Livewire::test(CartPage::class)
        ->call('decrement', $item->getKey())
        ->assertHasNoErrors();

    expect($item->fresh()->quantity)->toBe(2);
});

it('refuses to decrement a line already at 1, and does not go negative', function (): void {
    $cart = visitorCart();
    $variation = cartVariation(stock: 10);
    $item = cartLine($cart, $variation, 1);

    Livewire::test(CartPage::class)
        ->call('decrement', $item->getKey())
        ->assertHasErrors('line-'.$item->getKey());

    expect($item->fresh()->quantity)->toBe(1);
});

it('removes an owned line entirely', function (): void {
    $cart = visitorCart();
    $item = cartLine($cart, cartVariation(), 1);

    Livewire::test(CartPage::class)->call('remove', $item->getKey());

    expect(CartItem::query()->find($item->getKey()))->toBeNull();
});

// ── The typed quantity box ──────────────────────────────────────

it('resets the box to the stored quantity on non-numeric input, without an error', function (): void {
    $cart = visitorCart();
    $item = cartLine($cart, cartVariation(), 3);

    Livewire::test(CartPage::class)
        ->set('quantities.'.$item->getKey(), '')
        ->assertHasNoErrors()
        ->assertSet('quantities.'.$item->getKey(), 3);

    expect($item->fresh()->quantity)->toBe(3);
});

it('adds a line error and resets the box when the typed quantity is below 1', function (): void {
    $cart = visitorCart();
    $item = cartLine($cart, cartVariation(), 3);

    Livewire::test(CartPage::class)
        ->set('quantities.'.$item->getKey(), '0')
        ->assertHasErrors('line-'.$item->getKey())
        ->assertSet('quantities.'.$item->getKey(), 3);

    expect($item->fresh()->quantity)->toBe(3);
});

it('updates the row and clears a prior line error on a valid typed quantity', function (): void {
    $cart = visitorCart();
    $item = cartLine($cart, cartVariation(stock: 10), 1);

    Livewire::test(CartPage::class)
        ->set('quantities.'.$item->getKey(), '0') // first: a refusal, to leave an error behind
        ->set('quantities.'.$item->getKey(), '4') // then: a valid change
        ->assertHasNoErrors('line-'.$item->getKey());

    expect($item->fresh()->quantity)->toBe(4);
});

it('surfaces insufficient stock as a line error and snaps the box back', function (): void {
    $cart = visitorCart();
    $item = cartLine($cart, cartVariation(stock: 2), 1);

    Livewire::test(CartPage::class)
        ->set('quantities.'.$item->getKey(), '5')
        ->assertHasErrors('line-'.$item->getKey())
        ->assertSet('quantities.'.$item->getKey(), 1);

    expect($item->fresh()->quantity)->toBe(1);
});

it('touches the cart\'s expiry after a successful quantity change', function (): void {
    $cart = visitorCart();
    $item = cartLine($cart, cartVariation(stock: 10), 1);

    expect($cart->fresh()->expires_at)->toBeNull();

    Livewire::test(CartPage::class)->call('increment', $item->getKey());

    expect($cart->fresh()->expires_at)->not->toBeNull();
});

// ── Coupons ───────────────────────────────────────────────────────

it('applies a valid, whitespace-padded coupon code and clears the input', function (): void {
    $cart = visitorCart();
    cartLine($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = cartPageCoupon();

    Livewire::test(CartPage::class)
        ->set('couponCode', '  '.$coupon->code.'  ')
        ->call('applyCoupon')
        ->assertHasNoErrors()
        ->assertSet('couponCode', '');

    expect($cart->fresh()->coupon_id)->toBe($coupon->getKey());
});

it('adds a coupon error for an unrecognised code without touching the cart', function (): void {
    $cart = visitorCart();
    cartLine($cart, cartVariation(product: ['regular_price' => '100.00']), 1);

    Livewire::test(CartPage::class)
        ->set('couponCode', 'NOSUCHCODE')
        ->call('applyCoupon')
        ->assertHasErrors('coupon');

    expect($cart->fresh()->coupon_id)->toBeNull();
});

it('adds a coupon error when the Action itself refuses the code', function (): void {
    $cart = visitorCart();
    cartLine($cart, cartVariation(product: ['regular_price' => '30.00']), 1);
    $coupon = cartPageCoupon(['minimum_order_value' => '50.00']);

    Livewire::test(CartPage::class)
        ->set('couponCode', $coupon->code)
        ->call('applyCoupon')
        ->assertHasErrors('coupon');

    expect($cart->fresh()->coupon_id)->toBeNull();
});

it('removes an applied coupon', function (): void {
    $cart = visitorCart();
    cartLine($cart, cartVariation(), 1);
    $cart->update(['coupon_id' => cartPageCoupon()->getKey()]);

    Livewire::test(CartPage::class)->call('removeCoupon');

    expect($cart->fresh()->coupon_id)->toBeNull();
});

// ── discount() — the bridge into CalculateCouponDiscount ────────

it('shows zero discount and the undiscounted vat when no coupon is applied', function (): void {
    $cart = visitorCart();
    cartLine($cart, cartVariation(product: ['regular_price' => '100.00', 'vat_rate' => 20.00]), 1);

    $component = Livewire::test(CartPage::class);
    $totals = $component->instance()->totals();
    $discount = $component->instance()->discount();

    expect($discount['discount'])->toBe('0.00')
        ->and($discount['payable'])->toBe($totals['total'])
        ->and($discount['vat'])->toBe($totals['vat']);
});

it('computes discount, payable and coupon-aware vat for a scoped coupon matching part of the cart', function (): void {
    $cart = visitorCart();
    $matchedVariation = cartVariation(stock: 10, product: ['regular_price' => '80.00', 'vat_rate' => 20.00]);
    $unmatchedVariation = cartVariation(stock: 10, product: ['regular_price' => '20.00', 'vat_rate' => 20.00]);

    cartLine($cart, $matchedVariation, 5);   // matched: 5 * 80.00 = 400.00
    cartLine($cart, $unmatchedVariation, 1); // unmatched: 20.00

    $coupon = cartPageCoupon([
        'scope' => CouponScope::Products,
        'type' => CouponType::Percentage,
        'value' => '15.00',
    ]);
    $coupon->products()->attach($matchedVariation->product);

    $component = Livewire::test(CartPage::class)
        ->set('couponCode', $coupon->code)
        ->call('applyCoupon');

    $discount = $component->instance()->discount();

    // 15% of the matched 400.00 = 60.00. Payable: 420.00 - 60.00 = 360.00.
    // vat: (400.00 - 60.00) * 20/120 = 56.66, plus the unmatched line's
    // untouched 20.00 * 20/120 = 3.33 — both, not only the matched line's,
    // which is the exact regression CalculateCouponDiscount's fix covers.
    expect($discount['discount'])->toBe('60.00')
        ->and($discount['payable'])->toBe('360.00')
        ->and($discount['vat'])->toBe('59.99');
});

it('falls back to no discount once the applied coupon becomes inapplicable', function (): void {
    $cart = visitorCart();
    $coupon = cartPageCoupon(['minimum_order_value' => '50.00']);
    $variation = cartVariation(stock: 10, product: ['regular_price' => '60.00']);
    cartLine($cart, $variation, 1);

    $component = Livewire::test(CartPage::class)
        ->set('couponCode', $coupon->code)
        ->call('applyCoupon');

    expect($component->instance()->discount()['discount'])->toBe('6.00');

    // The coupon is still attached to the cart — nothing here calls
    // RemoveCoupon — but a price drop below the minimum makes it invalid on
    // the next render, same guard CalculateCouponDiscountTest pins directly.
    $variation->product->update(['regular_price' => '10.00']);

    $refreshed = Livewire::test(CartPage::class);
    $discount = $refreshed->instance()->discount();

    expect($discount['discount'])->toBe('0.00')
        ->and($discount['vat'])->toBe($refreshed->instance()->totals()['vat']);
});

// ── Initial render ──────────────────────────────────────────────

function cartWithVariation(int $quantity = 1, ?int $available = 100, int $minOrderQuantity = 1): array
{
    /** @var App\Models\Product $product */
    $product = App\Models\Product::factory()->create([
        'is_available' => true,
        'min_order_quantity' => $minOrderQuantity,
    ]);

    /** @var ProductVariation $variation */
    $variation = ProductVariation::factory()->for($product)->create(['is_available' => true]);

    if ($available !== null) {
        App\Models\Inventory::factory()->for($variation, 'productVariation')->create([
            'current_quantity' => $available,
            'reserved_quantity' => 0,
        ]);
    }

    $cart = visitorCart();
    $item = cartLine($cart, $variation, $quantity);

    return [$cart, $item, $variation];
}

it('renders the visitor\'s own cart items and server-computed totals', function (): void {
    [, $item] = cartWithVariation(quantity: 2);

    Livewire::test(CartPage::class)
        ->assertSee($item->productVariation->product->name)
        ->assertSet('quantities', [$item->getKey() => 2]);
});
