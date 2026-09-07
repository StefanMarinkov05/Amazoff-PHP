<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a coupon applies to. `Products` and `Categories` read their targets
 * from the `coupon_product` and `coupon_product_category` pivots; for
 * `EntireOrder` both pivots are ignored.
 */
enum CouponScope: string implements HasLabel
{
    case EntireOrder = 'entire_order';
    case Products = 'products';
    case Categories = 'categories';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::EntireOrder => 'Entire order',
            self::Products => 'Selected products',
            self::Categories => 'Selected categories',
        };
    }
}
