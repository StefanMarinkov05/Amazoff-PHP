<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Coupon\RemoveCoupon;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;

/*
 * No refusal to test, same shape as RemoveFromCartTest — what is worth
 * asserting is that this touches only carts.coupon_id.
 */

it('nulls the cart coupon_id', function (): void {
    $cart = emptyCart();
    $coupon = Coupon::factory()->create([
        'code' => 'REMOVEME',
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '5.00',
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => null,
        'max_discount_amount' => null,
    ]);
    $cart->update(['coupon_id' => $coupon->getKey()]);

    $result = app(RemoveCoupon::class)->handle($cart);

    expect($result->coupon_id)->toBeNull()
        ->and($cart->fresh()->coupon_id)->toBeNull();
});

it('is a no-op on a cart with no coupon applied', function (): void {
    $cart = emptyCart();

    $result = app(RemoveCoupon::class)->handle($cart);

    expect($result->coupon_id)->toBeNull();
});

it('leaves the cart itself and its items in place', function (): void {
    $cart = emptyCart();
    $item = app(AddToCart::class)->handle($cart, cartVariation(), 1);
    $cart->update(['coupon_id' => Coupon::factory()->create()->getKey()]);

    app(RemoveCoupon::class)->handle($cart);

    expect($cart->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull();
});

it('takes only the cart, so a coupon id in the request cannot be tampered with', function (): void {
    // RemoveCoupon::handle() has no coupon parameter at all — this pins the
    // signature itself as the guard, not a runtime check.
    $method = new ReflectionMethod(RemoveCoupon::class, 'handle');

    expect($method->getNumberOfParameters())->toBe(1);
});
