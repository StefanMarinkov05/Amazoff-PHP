<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a coupon's `value` column is interpreted. The arithmetic itself lives in
 * the discount Action, where it can use bcmath — an enum is the wrong place
 * for money handling.
 */
enum CouponType: string implements HasLabel
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage off',
            self::Fixed => 'Fixed amount off',
        };
    }
}
