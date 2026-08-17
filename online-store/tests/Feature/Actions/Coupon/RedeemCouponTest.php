<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Coupon\ApplyCoupon;
use App\Actions\Coupon\RedeemCoupon;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Exceptions\CouponNotApplicableException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * An order with one order_item snapshotting the given product's price/rate.
 *
 * @param  array<string, mixed>  $orderOverrides
 */
function orderWithLine(Product $product, string $lineTotal = '100.00', array $orderOverrides = []): Order
{
    $order = Order::factory()->create(array_merge([
        'subtotal_amount' => $lineTotal,
        'discount_amount' => 0,
    ], $orderOverrides));

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $product->getKey(),
        'quantity' => 1,
        'unit_price' => $lineTotal,
        'line_total' => $lineTotal,
        'discount_amount' => 0,
        'vat_rate' => (string) $product->vat_rate,
    ]);

    return $order->fresh();
}

function redeemableCoupon(array $overrides = []): Coupon
{
    return Coupon::factory()->create(array_merge([
        'code' => 'RC'.fake()->unique()->numberBetween(1000, 999999),
        'type' => CouponType::Fixed,
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

it('records a redemption with the discount amount', function (): void {
    $product = Product::factory()->create(['vat_rate' => 20.00]);
    $order = orderWithLine($product, '100.00', ['email' => 'buyer@example.com']);
    $coupon = redeemableCoupon(['value' => '10.00']);

    $redemption = app(RedeemCoupon::class)->handle($coupon, $order);

    expect($redemption->coupon_id)->toBe($coupon->getKey())
        ->and($redemption->order_id)->toBe($order->getKey())
        ->and((string) $redemption->discount_amount)->toBe('10.00')
        ->and(CouponRedemption::count())->toBe(1);
});

it('hashes the email rather than storing it', function (): void {
    $product = Product::factory()->create();
    $order = orderWithLine($product, '100.00', ['email' => 'buyer@example.com']);
    $coupon = redeemableCoupon();

    $redemption = app(RedeemCoupon::class)->handle($coupon, $order);

    expect($redemption->email_hash)->not->toBe('buyer@example.com')
        ->and(mb_strlen($redemption->email_hash))->toBe(64);
});

it('hashes case- and whitespace-insensitively', function (): void {
    $product = Product::factory()->create();
    $coupon = redeemableCoupon(['usage_limit_per_customer' => 1]);

    $orderOne = orderWithLine($product, '100.00', ['email' => 'Buyer@Example.com']);
    app(RedeemCoupon::class)->handle($coupon, $orderOne);

    $orderTwo = orderWithLine($product, '100.00', ['email' => ' buyer@example.com ']);

    // Same person, differently cased/padded email — the per-customer cap of
    // 1 must see these as the same customer.
    expect(fn () => app(RedeemCoupon::class)->handle($coupon, $orderTwo))
        ->toThrow(CouponNotApplicableException::class);
});

it('refuses when the coupon was disabled after being applied at cart time', function (): void {
    // The plan's own test obligation: apply succeeds, an admin disables the
    // coupon before order creation, redemption still refuses.
    $product = Product::factory()->create();
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = redeemableCoupon();

    app(ApplyCoupon::class)->handle($cart, $coupon->code);
    $coupon->update(['is_active' => false]);

    $order = orderWithLine($product, '100.00');

    expect(fn () => app(RedeemCoupon::class)->handle($coupon->fresh(), $order))
        ->toThrow(CouponNotApplicableException::class);

    expect(CouponRedemption::count())->toBe(0);
});

it('refuses once the total usage limit is reached', function (): void {
    $product = Product::factory()->create();
    $coupon = redeemableCoupon(['total_usage_limit' => 1]);

    $orderOne = orderWithLine($product, '100.00', ['email' => 'first@example.com']);
    app(RedeemCoupon::class)->handle($coupon, $orderOne);

    $orderTwo = orderWithLine($product, '100.00', ['email' => 'second@example.com']);

    expect(fn () => app(RedeemCoupon::class)->handle($coupon->fresh(), $orderTwo))
        ->toThrow(CouponNotApplicableException::class);

    expect(CouponRedemption::count())->toBe(1);
});

it('refuses once the same customer reaches the per-customer limit', function (): void {
    $product = Product::factory()->create();
    $coupon = redeemableCoupon(['usage_limit_per_customer' => 1]);

    $orderOne = orderWithLine($product, '100.00', ['email' => 'same@example.com']);
    app(RedeemCoupon::class)->handle($coupon, $orderOne);

    $orderTwo = orderWithLine($product, '100.00', ['email' => 'same@example.com']);

    expect(fn () => app(RedeemCoupon::class)->handle($coupon->fresh(), $orderTwo))
        ->toThrow(CouponNotApplicableException::class);

    expect(CouponRedemption::count())->toBe(1);
});

it('allows two different customers each within their own per-customer limit', function (): void {
    $product = Product::factory()->create();
    $coupon = redeemableCoupon(['usage_limit_per_customer' => 1]);

    $orderOne = orderWithLine($product, '100.00', ['email' => 'one@example.com']);
    $orderTwo = orderWithLine($product, '100.00', ['email' => 'two@example.com']);

    app(RedeemCoupon::class)->handle($coupon, $orderOne);
    app(RedeemCoupon::class)->handle($coupon->fresh(), $orderTwo);

    expect(CouponRedemption::count())->toBe(2);
});

it('returns the existing row rather than inserting twice for the same order', function (): void {
    // UNIQUE(coupon_id, order_id) caught via UniqueConstraintViolationException,
    // not checked-then-acted — CLAUDE.md's idempotency rule. Simulated here by
    // calling twice in sequence for the same order.
    $product = Product::factory()->create();
    $order = orderWithLine($product, '100.00');
    $coupon = redeemableCoupon();

    $first = app(RedeemCoupon::class)->handle($coupon, $order);
    $second = app(RedeemCoupon::class)->handle($coupon->fresh(), $order);

    expect($second->getKey())->toBe($first->getKey())
        ->and(CouponRedemption::count())->toBe(1);
});

it('refuses a scoped coupon with no matching order line', function (): void {
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $order = orderWithLine($product, '100.00');
    $coupon = redeemableCoupon(['scope' => CouponScope::Products]);
    $coupon->products()->attach($otherProduct);

    expect(fn () => app(RedeemCoupon::class)->handle($coupon, $order))
        ->toThrow(CouponNotApplicableException::class);
});

it('takes no actor parameter', function (): void {
    // Decision 4: RedeemCoupon runs inside an already-authorized checkout
    // request; identity derives from the Order, not from a caller.
    $method = new ReflectionMethod(RedeemCoupon::class, 'handle');

    expect($method->getNumberOfParameters())->toBe(2);
});
