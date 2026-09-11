<?php

declare(strict_types=1);

use App\Actions\Cart\RestoreCartFromOrder;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use App\Models\User;
use App\Support\Resolvers\ResolveCurrentCart;
use Illuminate\Support\Facades\Session;

/*
 * The basket a customer gets back when they cancel their own checkout
 * (ADR-0022's deliberate counterpart to the abandonment sweep).
 *
 * The interesting property is not "rows were copied" — it is that the
 * restored cart is one `ResolveCurrentCart` will actually hand back. The
 * original cart cannot be: it produced an order, and handing a spent cart
 * back is what broke checkout permanently for a session on 2026-09-05.
 */

/** An order with one line against a real, live variation. */
function orderToRestore(int $quantity = 2, ?User $owner = null): Order
{
    $variation = variationWithStock(current: 10, reserved: $quantity);

    $order = Order::factory()->create([
        'user_id' => $owner?->getKey(),
        'anonymized_at' => null,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return $order->fresh();
}

it('copies the order lines into a new cart', function (): void {
    $order = orderToRestore(quantity: 3);
    $line = $order->orderItems()->sole();

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-restore');

    expect($cart->cartItems()->count())->toBe(1)
        ->and($cart->cartItems()->sole()->product_variation_id)->toBe($line->product_variation_id)
        ->and($cart->cartItems()->sole()->quantity)->toBe(3);
});

/*
 * The load-bearing one. A restored cart that the resolver will not return
 * is no better than no cart at all — the customer would still land on an
 * empty basket.
 */
it('produces a cart the visitor actually resolves to', function (): void {
    $order = orderToRestore();

    app(RestoreCartFromOrder::class)->handle($order, null, Session::getId());

    expect(ResolveCurrentCart::existing())->not->toBeNull()
        ->and(ResolveCurrentCart::existing()->cartItems()->count())->toBe(1);
});

it('leaves the original cart spent rather than reviving it', function (): void {
    $original = Cart::factory()->create(['session_id' => Session::getId(), 'user_id' => null, 'coupon_id' => null]);
    $order = orderToRestore();
    $order->update(['cart_id' => $original->getKey()]);

    $restored = app(RestoreCartFromOrder::class)->handle($order, null, Session::getId());

    // A different row: the audit link from the order back to the basket it
    // came from has to survive, and UNIQUE(orders.cart_id) means the old
    // one can never be checked out again.
    expect($restored->is($original))->toBeFalse()
        ->and($order->fresh()->cart_id)->toBe($original->getKey());
});

it('keys the restored cart to a signed-in customer rather than the session', function (): void {
    $user = User::factory()->create();
    $order = orderToRestore(owner: $user);

    $cart = app(RestoreCartFromOrder::class)->handle($order, $user->getKey(), 'sess-ignored');

    expect($cart->user_id)->toBe($user->getKey())
        // Not both: a customer's cart is found by user_id, and leaving a
        // session_id on it would make the same row match a later guest.
        ->and($cart->session_id)->toBeNull();
});

/*
 * A force-deleted variation nulls order_items.product_variation_id through
 * nullOnDelete(). Inserting a cart line from that would violate the foreign
 * key — a 500 on a Cancel button.
 */
it('skips a line whose variation was force-deleted', function (): void {
    $order = orderToRestore();
    $order->orderItems()->update(['product_variation_id' => null]);

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-dead');

    expect($cart->cartItems()->count())->toBe(0);
});

it('skips a line whose variation was soft-deleted since the order', function (): void {
    $order = orderToRestore();
    ProductVariation::query()->whereKey($order->orderItems()->sole()->product_variation_id)->delete();

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-trashed');

    // A cart line the customer can neither buy nor fix is worse than a
    // missing one — CalculateCartTotals already drops these from a subtotal.
    expect($cart->cartItems()->count())->toBe(0);
});

it('restores the surviving lines when only one of several is dead', function (): void {
    $order = orderToRestore(quantity: 1);
    $second = variationWithStock(current: 5);
    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $second->product_id,
        'product_variation_id' => $second->getKey(),
        'quantity' => 4,
    ]);
    $order->orderItems()->whereKeyNot($order->orderItems()->latest('id')->first()->getKey())
        ->update(['product_variation_id' => null]);

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-mixed');

    expect($cart->cartItems()->count())->toBe(1)
        ->and($cart->cartItems()->sole()->quantity)->toBe(4);
});

/*
 * The usual real-world shape: by the time Cancel is pressed the visitor
 * already has an empty cart, because CreateOrder consumed the original and
 * the next cart-reading render opened a fresh one. The lines must land in
 * *that* cart — ResolveCurrentCart returns the first unspent cart, so a
 * third row would leave the customer looking at an empty basket.
 */
it('restores into the visitors existing unspent cart rather than opening another', function (): void {
    $order = orderToRestore();
    $existing = Cart::factory()->create([
        'session_id' => 'sess-reuse',
        'user_id' => null,
        'coupon_id' => null,
    ]);

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-reuse');

    expect($cart->is($existing))->toBeTrue()
        ->and(Cart::query()->where('session_id', 'sess-reuse')->count())->toBe(1)
        ->and($cart->cartItems()->count())->toBe(1);
});

it('overwrites a line the reused cart already held', function (): void {
    $order = orderToRestore(quantity: 5);
    $variationId = $order->orderItems()->sole()->product_variation_id;

    $existing = Cart::factory()->create([
        'session_id' => 'sess-dupe',
        'user_id' => null,
        'coupon_id' => null,
    ]);
    $existing->cartItems()->create([
        'product_variation_id' => $variationId,
        'quantity' => 1,
    ]);

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-dupe');

    // UNIQUE(cart_id, product_variation_id) forbids a second row, and the
    // order's quantity is what the customer was actually buying.
    expect($cart->cartItems()->count())->toBe(1)
        ->and($cart->cartItems()->sole()->quantity)->toBe(5);
});

it('carries the coupon back onto the restored cart', function (): void {
    $order = orderToRestore();
    $coupon = Coupon::factory()->create();
    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'order_id' => $order->getKey(),
    ]);

    $cart = app(RestoreCartFromOrder::class)->handle($order, null, 'sess-coupon');

    // Carried, not re-validated: RedeemCoupon re-checks everything under a
    // lock at the next checkout, so a since-expired code costs a refusal
    // the customer can act on, not a silently dropped discount.
    expect($cart->coupon_id)->toBe($coupon->getKey());
});

it('restores a quantity that now exceeds available stock rather than refusing', function (): void {
    $variation = variationWithStock(current: 1);
    $order = Order::factory()->create(['anonymized_at' => null]);
    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
        'quantity' => 50,
    ]);

    $cart = app(RestoreCartFromOrder::class)->handle($order->fresh(), null, 'sess-oversold');

    // MergeGuestCart's precedent: the basket comes back as it was, and
    // UpdateCartItemQuantity / CreateOrder raise it on the next write.
    // Losing the basket to pre-empt a message the cart page already shows
    // would be the worse trade.
    expect($cart->cartItems()->sole()->quantity)->toBe(50);
});
