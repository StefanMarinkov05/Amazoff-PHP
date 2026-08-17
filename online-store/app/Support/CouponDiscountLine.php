<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One line of `CalculateCouponDiscount`'s input, normalized so the same
 * bcmath works whether the source is a live `CartItem` (current price via
 * `ResolveVariationPrice`) or a placed order's `OrderItem` (`unit_price`
 * already snapshotted, §17). Neither model is referenced directly —
 * `CalculateCouponDiscount` only reads this shape.
 */
final class CouponDiscountLine
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $productCategoryId,
        public readonly string $lineTotal,
        public readonly string $vatRate,
    ) {}
}
