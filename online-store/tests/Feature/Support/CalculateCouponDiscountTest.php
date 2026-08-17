<?php

declare(strict_types=1);

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Exceptions\CouponNotApplicableException;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/*
 * The bcmath and the refusal rules, isolated from both callers. ApplyCoupon
 * and RedeemCoupon each build their own Collection<CouponDiscountLine> from
 * their own source (CartItem / OrderItem) — this file constructs lines
 * directly so the arithmetic is pinned without either Action in the way.
 */

function couponDiscountLine(
    int $productId = 1,
    ?int $categoryId = null,
    string $lineTotal = '100.00',
    string $vatRate = '20.00',
): CouponDiscountLine {
    return new CouponDiscountLine($productId, $categoryId, $lineTotal, $vatRate);
}

it('discounts a percentage coupon against the matched subtotal', function (): void {
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Percentage,
        'scope' => CouponScope::EntireOrder,
        'value' => '10.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $lines = collect([couponDiscountLine(lineTotal: '100.00')]);

    $result = CalculateCouponDiscount::forLines($coupon, $lines, '100.00');

    expect($result['discount'])->toBe('10.00');
});

it('discounts a fixed coupon by the flat value', function (): void {
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '15.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $lines = collect([couponDiscountLine(lineTotal: '100.00')]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '100.00')['discount'])->toBe('15.00');
});

it('caps a percentage discount at max_discount_amount', function (): void {
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Percentage,
        'scope' => CouponScope::EntireOrder,
        'value' => '50.00',
        'max_discount_amount' => '20.00',
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $lines = collect([couponDiscountLine(lineTotal: '100.00')]);

    // 50% of 100 is 50, but the cap is 20.
    expect(CalculateCouponDiscount::forLines($coupon, $lines, '100.00')['discount'])->toBe('20.00');
});

it('never discounts more than the matched subtotal', function (): void {
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '500.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $lines = collect([couponDiscountLine(lineTotal: '100.00')]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '100.00')['discount'])->toBe('100.00');
});

it('returns zero VAT for a matched line that costs nothing', function (): void {
    // A free/zero-price line: the matched subtotal is exactly 0.00, which is
    // the guard vatAfterDiscount() takes before dividing by it.
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '0.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $lines = collect([couponDiscountLine(lineTotal: '0.00')]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '0.00')['vat'])->toBe('0.00');
});

it('re-derives VAT against the discounted total at a single rate', function (): void {
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '20.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $lines = collect([couponDiscountLine(lineTotal: '100.00', vatRate: '20.00')]);

    // 100 - 20 discount = 80 gross, at 20% VAT: 80 * 20/120 = 13.33.
    expect(CalculateCouponDiscount::forLines($coupon, $lines, '100.00')['vat'])->toBe('13.33');
});

it('apportions the discount across mixed VAT rates without storing it per line', function (): void {
    $coupon = Coupon::factory()->create([
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '40.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    // Two equal-value lines at different rates; the 40 discount splits 20/20.
    $lines = collect([
        couponDiscountLine(productId: 1, lineTotal: '100.00', vatRate: '20.00'),
        couponDiscountLine(productId: 2, lineTotal: '100.00', vatRate: '9.00'),
    ]);

    $result = CalculateCouponDiscount::forLines($coupon, $lines, '200.00');

    // Line 1: (100-20) * 20/120 = 13.33. Line 2: (100-20) * 9/109 = 6.60
    // (bcdiv truncates, not rounds, at scale 2).
    expect($result['discount'])->toBe('40.00')
        ->and($result['vat'])->toBe('19.93');
});

it('refuses an inactive coupon', function (): void {
    $coupon = Coupon::factory()->create(['is_active' => false, 'scope' => CouponScope::EntireOrder]);
    $lines = collect([couponDiscountLine()]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon, $lines, '100.00'))
        ->toThrow(CouponNotApplicableException::class);
});

it('refuses a coupon before its start window', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'starts_at' => Carbon::now()->addDay(),
        'ends_at' => null,
    ]);
    $lines = collect([couponDiscountLine()]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon, $lines, '100.00'))
        ->toThrow(CouponNotApplicableException::class);
});

it('refuses a coupon after its end window', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'starts_at' => null,
        'ends_at' => Carbon::now()->subDay(),
    ]);
    $lines = collect([couponDiscountLine()]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon, $lines, '100.00'))
        ->toThrow(CouponNotApplicableException::class);
});

it('accepts a coupon inside its window', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'type' => CouponType::Fixed,
        'value' => '5.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'starts_at' => Carbon::now()->subDay(),
        'ends_at' => Carbon::now()->addDay(),
    ]);
    $lines = collect([couponDiscountLine(lineTotal: '100.00')]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '100.00')['discount'])->toBe('5.00');
});

it('refuses an order below the minimum', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => '50.00',
    ]);
    $lines = collect([couponDiscountLine(lineTotal: '30.00')]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon, $lines, '30.00'))
        ->toThrow(CouponNotApplicableException::class);
});

it('accepts an order exactly at the minimum', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'type' => CouponType::Fixed,
        'value' => '5.00',
        'max_discount_amount' => null,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => '50.00',
    ]);
    $lines = collect([couponDiscountLine(lineTotal: '50.00')]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '50.00')['discount'])->toBe('5.00');
});

it('refuses a product-scoped coupon with no matching line', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::Products,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => null,
    ]);
    $coupon->products()->attach(Product::factory()->create());

    // Line's productId (1) matches nothing the coupon was attached to.
    $lines = collect([couponDiscountLine(productId: 999999)]);

    expect(fn () => CalculateCouponDiscount::forLines($coupon, $lines, '100.00'))
        ->toThrow(CouponNotApplicableException::class);
});

it('discounts only the matching lines for a product-scoped coupon', function (): void {
    $matchedProduct = Product::factory()->create();
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::Products,
        'type' => CouponType::Percentage,
        'value' => '10.00',
        'max_discount_amount' => null,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => null,
    ]);
    $coupon->products()->attach($matchedProduct);

    $lines = collect([
        couponDiscountLine(productId: $matchedProduct->getKey(), lineTotal: '100.00'),
        couponDiscountLine(productId: 999999, lineTotal: '100.00'),
    ]);

    // 10% of only the matched 100, not the full 200.
    expect(CalculateCouponDiscount::forLines($coupon, $lines, '200.00')['discount'])->toBe('10.00');
});

it('discounts only the matching lines for a category-scoped coupon', function (): void {
    $matchedCategory = ProductCategory::factory()->create();
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::Categories,
        'type' => CouponType::Fixed,
        'value' => '5.00',
        'max_discount_amount' => null,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => null,
    ]);
    $coupon->productCategories()->attach($matchedCategory);

    $lines = collect([
        couponDiscountLine(productId: 1, categoryId: $matchedCategory->getKey(), lineTotal: '100.00'),
        couponDiscountLine(productId: 2, categoryId: 999999, lineTotal: '100.00'),
    ]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '200.00')['discount'])->toBe('5.00');
});

it('applies an entire-order coupon to every line regardless of pivots', function (): void {
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'type' => CouponType::Percentage,
        'value' => '10.00',
        'max_discount_amount' => null,
        'starts_at' => null,
        'ends_at' => null,
        'minimum_order_value' => null,
    ]);

    $lines = collect([
        couponDiscountLine(productId: 1, lineTotal: '100.00'),
        couponDiscountLine(productId: 2, lineTotal: '100.00'),
    ]);

    expect(CalculateCouponDiscount::forLines($coupon, $lines, '200.00')['discount'])->toBe('20.00');
});
