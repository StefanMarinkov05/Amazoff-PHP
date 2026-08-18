<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Coupon\ApplyCoupon;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Exceptions\CouponNotApplicableException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use Illuminate\Support\Carbon;

/*
 * cartVariation() and emptyCart() come from tests/Pest.php.
 */

function activeCoupon(array $overrides = []): Coupon
{
    return Coupon::factory()->create(array_merge([
        'code' => 'SAVE'.fake()->unique()->numberBetween(1000, 999999),
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

it('attaches the coupon to the cart', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon();

    $result = app(ApplyCoupon::class)->handle($cart, $coupon->code);

    expect($result->coupon_id)->toBe($coupon->getKey());
});

it('overwrites a previously applied coupon silently', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $first = activeCoupon();
    $second = activeCoupon();

    app(ApplyCoupon::class)->handle($cart, $first->code);
    $result = app(ApplyCoupon::class)->handle($cart, $second->code);

    expect($result->coupon_id)->toBe($second->getKey());
});

it('refuses an inactive coupon', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon(['is_active' => false]);

    expect(fn () => app(ApplyCoupon::class)->handle($cart, $coupon->code))
        ->toThrow(CouponNotApplicableException::class);

    expect($cart->fresh()->coupon_id)->toBeNull();
});

it('refuses a coupon outside its window', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon(['starts_at' => Carbon::now()->addDay()]);

    expect(fn () => app(ApplyCoupon::class)->handle($cart, $coupon->code))
        ->toThrow(CouponNotApplicableException::class);
});

it('refuses a cart below the minimum order value', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '20.00']), 1);
    $coupon = activeCoupon(['minimum_order_value' => '50.00']);

    expect(fn () => app(ApplyCoupon::class)->handle($cart, $coupon->code))
        ->toThrow(CouponNotApplicableException::class);
});

it('refuses a scoped coupon with no matching line in the cart', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $otherProduct = Product::factory()->create();
    $coupon = activeCoupon(['scope' => CouponScope::Products]);
    $coupon->products()->attach($otherProduct);

    expect(fn () => app(ApplyCoupon::class)->handle($cart, $coupon->code))
        ->toThrow(CouponNotApplicableException::class);
});

it('refuses when the logged-in customer already reached the per-customer cap', function (): void {
    $user = User::factory()->create();
    $cart = emptyCart($user);
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon(['usage_limit_per_customer' => 1]);

    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'order_id' => Order::factory()->create(['user_id' => $user->getKey()])->getKey(),
        'user_id' => $user->getKey(),
    ]);

    expect(fn () => app(ApplyCoupon::class)->handle($cart, $coupon->code))
        ->toThrow(CouponNotApplicableException::class);
});

it('applies an uncapped coupon for a logged-in customer without counting redemptions', function (): void {
    // usage_limit_per_customer null skips guardPerCustomerLimit's query
    // entirely — a logged-in customer is not the guest-only branch above.
    $user = User::factory()->create();
    $cart = emptyCart($user);
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon(['usage_limit_per_customer' => null]);

    $result = app(ApplyCoupon::class)->handle($cart, $coupon->code);

    expect($result->coupon_id)->toBe($coupon->getKey());
});

it('does not check the per-customer cap for a guest cart', function (): void {
    // Decision 6: a guest cart has no email yet, so the per-customer check
    // cannot run here — it is deferred to checkout. A guest applying is not
    // refused for a cap that cannot yet be evaluated.
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon(['usage_limit_per_customer' => 1]);

    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'email_hash' => 'irrelevant-here',
    ]);

    $result = app(ApplyCoupon::class)->handle($cart, $coupon->code);

    expect($result->coupon_id)->toBe($coupon->getKey());
});

/*
 * "What happens when you update a coupon while it is applied" — decision 7.
 * Nothing is stored on the cart to go stale, so an admin edit is picked up
 * on the very next CalculateCouponDiscount call with no extra write. This
 * is a Support-class-level test rather than a concurrency one: the sequence
 * is deterministic (apply, then edit, then re-read), no second process
 * needed to prove it.
 */

it('leaves carts.coupon_id untouched when the coupon is edited after being applied', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon();

    app(ApplyCoupon::class)->handle($cart, $coupon->code);
    $coupon->update(['is_active' => false]);

    // Decision 7: the row is left alone until the customer acts via RemoveCoupon.
    expect($cart->fresh()->coupon_id)->toBe($coupon->getKey());
});

it('computes no discount on the next render once the coupon is disabled after being applied', function (): void {
    $cart = emptyCart();
    $item = app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon();

    app(ApplyCoupon::class)->handle($cart, $coupon->code);
    $coupon->update(['is_active' => false]);

    // CalculateCartTotals/the coupon calculator re-validates on every render
    // rather than trusting the stale carts.coupon_id — the disabled coupon
    // now refuses, same as if it had never been valid.
    $lines = collect([new CouponDiscountLine(
        productId: $item->productVariation->product_id,
        productCategoryId: $item->productVariation->product->product_category_id,
        lineTotal: '100.00',
        vatRate: '20.00',
    )]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon->fresh(), $lines, '100.00'))
        ->toThrow(CouponNotApplicableException::class);
});

it('picks up a raised minimum order value on the next render', function (): void {
    $cart = emptyCart();
    $item = app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = activeCoupon(['minimum_order_value' => null]);

    app(ApplyCoupon::class)->handle($cart, $coupon->code);
    $coupon->update(['minimum_order_value' => '500.00']);

    $lines = collect([new CouponDiscountLine(
        productId: $item->productVariation->product_id,
        productCategoryId: null,
        lineTotal: '100.00',
        vatRate: '20.00',
    )]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon->fresh(), $lines, '100.00'))
        ->toThrow(CouponNotApplicableException::class);
});
