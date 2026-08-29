<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Exceptions\CouponNotApplicableException;
use App\Models\Coupon;
use Illuminate\Support\Collection;

/**
 * The discount a coupon would apply against a set of order lines, at
 * current prices — never stored, same status as `CalculateCartTotals`. A
 * function, not an Action: it writes nothing, so ADR-0007's threshold for a
 * command class does not apply. `misc/coupon-actions-plan.md`, decisions 3
 * and 8.
 *
 * Composed by `CalculateCartTotals` for cart-page display (via
 * `ApplyCoupon`, lines built from `CartItem` at current price) and by
 * `RedeemCoupon` for the authoritative figure against an order's
 * snapshotted `OrderItem` rows — one implementation of the bcmath, not two
 * kept in sync by hand. Both build `CouponDiscountLine`s from their own
 * source rather than this class knowing either model.
 *
 * Refuses (never returns a silent zero) when the coupon does not apply at
 * all: inactive, outside its window, below the minimum order value, or
 * scoped to products/categories with no matching line. Checking whether the
 * *usage limits* are still available is `RedeemCoupon`'s job, not this
 * class's — that requires a `coupons` row lock this pure function does not
 * take.
 */
final class CalculateCouponDiscount
{
    /**
     * @param  Collection<int, CouponDiscountLine>  $lines
     * @return array{discount: string, vat: string}
     *
     * @throws CouponNotApplicableException
     */
    public static function forLines(Coupon $coupon, Collection $lines, string $subtotal): array
    {
        self::guardActive($coupon);
        self::guardWindow($coupon);
        self::guardMinimum($coupon, $subtotal);

        $matched = self::matchingLines($coupon, $lines);

        if ($matched->isEmpty()) {
            throw CouponNotApplicableException::outOfScope($coupon);
        }

        $matchedSubtotal = '0.00';

        foreach ($matched as $line) {
            $matchedSubtotal = (string) Money::of($matchedSubtotal)->add(Money::of($line->lineTotal));
        }

        $discount = self::discountAmount($coupon, $matchedSubtotal);

        // VAT is re-derived against (matched subtotal - discount), one rate
        // extraction per line so a scoped coupon spanning several VAT rates
        // is still correct. The discount itself stays one order-level figure
        // (decision 3) — this apportionment is notional, computed here and
        // never written back onto a line.
        $vat = self::vatAfterDiscount($matched, $discount, $matchedSubtotal);

        return ['discount' => $discount, 'vat' => $vat];
    }

    private static function guardActive(Coupon $coupon): void
    {
        if (! $coupon->is_active) {
            throw CouponNotApplicableException::inactive($coupon);
        }
    }

    private static function guardWindow(Coupon $coupon): void
    {
        $now = now();

        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            throw CouponNotApplicableException::outsideWindow($coupon);
        }

        if ($coupon->ends_at !== null && $now->gt($coupon->ends_at)) {
            throw CouponNotApplicableException::outsideWindow($coupon);
        }
    }

    private static function guardMinimum(Coupon $coupon, string $subtotal): void
    {
        if ($coupon->minimum_order_value === null) {
            return;
        }

        if (Money::of($subtotal)->isLessThan(Money::of((string) $coupon->minimum_order_value))) {
            throw CouponNotApplicableException::belowMinimum($coupon, $subtotal);
        }
    }

    /**
     * @param  Collection<int, CouponDiscountLine>  $lines
     * @return Collection<int, CouponDiscountLine>
     */
    private static function matchingLines(Coupon $coupon, Collection $lines): Collection
    {
        if ($coupon->scope === CouponScope::EntireOrder) {
            return $lines;
        }

        if ($coupon->scope === CouponScope::Products) {
            $productIds = $coupon->products()->pluck('products.id')->all();

            return $lines->filter(
                fn (CouponDiscountLine $line): bool => in_array($line->productId, $productIds, true)
            );
        }

        // CouponScope::Categories
        $categoryIds = $coupon->productCategories()->pluck('product_categories.id')->all();

        return $lines->filter(
            fn (CouponDiscountLine $line): bool => $line->productCategoryId !== null
                && in_array($line->productCategoryId, $categoryIds, true)
        );
    }

    private static function discountAmount(Coupon $coupon, string $matchedSubtotal): string
    {
        $matched = Money::of($matchedSubtotal);

        // A percentage coupon takes a share *of* the matched subtotal, so it
        // is shareOf against a 100 total rather than percentageOf, which
        // extracts a portion already included in the amount.
        $raw = $coupon->type === CouponType::Percentage
            ? Money::of((string) $coupon->value)->shareOf($matched, Money::of('100'))
            : Money::of((string) $coupon->value);

        // A fixed-amount coupon, or a percentage coupon with no cap, never
        // discounts more than the lines it matched.
        $capped = $raw->cappedAt($matched);

        if ($coupon->max_discount_amount !== null) {
            $capped = $capped->cappedAt(Money::of((string) $coupon->max_discount_amount));
        }

        return (string) $capped;
    }

    /**
     * @param  Collection<int, CouponDiscountLine>  $matched
     */
    private static function vatAfterDiscount(Collection $matched, string $discount, string $matchedSubtotal): string
    {
        $total = Money::of($matchedSubtotal);

        if ($total->isZero()) {
            return (string) Money::zero();
        }

        $vat = Money::zero();
        $pool = Money::of($discount);

        foreach ($matched as $line) {
            $lineTotal = Money::of($line->lineTotal);

            // This line's share of the discount, proportional to its share
            // of the matched subtotal — computed here only, never persisted
            // per line (decision 3).
            $lineAfterDiscount = $lineTotal->subtract($lineTotal->shareOf($pool, $total));

            $vat = $vat->add($lineAfterDiscount->percentageOf($line->vatRate));
        }

        return (string) $vat;
    }
}
