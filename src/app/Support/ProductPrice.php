<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * A resolved price, as a page needs to render it: what to charge, what to
 * strike through, and whether a sale is running.
 *
 * Immutable on purpose. The four values are derived together from one reading
 * of the discount window, so nothing downstream can hold a `percent` that
 * disagrees with its `current`.
 *
 * Built through `make()` rather than the constructor by both resolvers —
 * `ResolveProductPrice` for a product, `ResolveVariationPrice` for a
 * variation — which is what keeps one definition of "how a discount becomes a
 * displayed price" across the two levels. ADR-0014.
 */
final class ProductPrice
{
    public function __construct(
        public readonly string $current,
        public readonly string $regular,
        public readonly bool $onSale,
        public readonly int $percent,
    ) {}

    /**
     * @param  string  $regular  the undiscounted price
     * @param  string|null  $discount  the sale price, or null when there is none
     * @param  bool  $windowOpen  whether the product's discount schedule is live now
     */
    public static function make(string $regular, ?string $discount, bool $windowOpen): self
    {
        $onSale = $discount !== null && $windowOpen;
        $current = $onSale ? $discount : $regular;

        return new self(
            current: $current,
            regular: $regular,
            onSale: $onSale,
            percent: $onSale ? self::percentOff($regular, $current) : 0,
        );
    }

    private static function percentOff(string $regular, string $current): int
    {
        if (! is_numeric($regular) || ! is_numeric($current)) {
            throw new InvalidArgumentException('ProductPrice::percentOff() requires numeric amounts.');
        }

        if (bccomp($regular, '0.00', 2) <= 0) {
            return 0;
        }

        $saving = bcsub($regular, $current, 2);

        if (bccomp($saving, '0.00', 2) <= 0) {
            return 0;
        }

        return (int) round((float) bcmul(bcdiv($saving, $regular, 4), '100', 2));
    }
}
