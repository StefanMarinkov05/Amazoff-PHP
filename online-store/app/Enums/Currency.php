<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The currencies the shop can quote and settle in. EUR is the default and,
 * today, the only one any catalogue price is expressed in — `orders.currency`
 * and `payments.currency` snapshot what a given order was actually placed in,
 * which is the part that must survive a later change of default.
 *
 * An enum rather than a lookup table, and not the roles exception: adding a
 * currency is never only data. It needs a rounding rule, a symbol, a decimal
 * count, and usually a payment-provider capability check — all of which are
 * code. §3.5's runtime-editability requirement, which is why roles are rows,
 * has no equivalent here.
 *
 * `minorUnitDigits()` exists because 2 is not universal — JPY has 0, and
 * writing `decimal:2` arithmetic against a zero-decimal currency silently
 * multiplies every amount by 100. Nothing depends on it yet; it is here so
 * that the assumption is stated rather than buried in the columns.
 *
 * `reference/schema/open-schema-questions.md` records why the catalogue itself has
 * no currency column and what real multi-currency would need instead.
 */
enum Currency: string implements HasLabel
{
    case EUR = 'EUR';
    case BGN = 'BGN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function default(): self
    {
        return self::EUR;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::EUR => 'Euro (EUR)',
            self::BGN => 'Bulgarian lev (BGN)',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => '€',
            self::BGN => 'лв.',
        };
    }

    /**
     * Decimal places this currency settles in. Every amount column in the
     * schema is `decimal(n,2)`, which is correct for both cases here and
     * would need revisiting before adding a zero-decimal currency.
     */
    public function minorUnitDigits(): int
    {
        return match ($this) {
            self::EUR, self::BGN => 2,
        };
    }
}
