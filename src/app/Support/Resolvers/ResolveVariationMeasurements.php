<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\Product;
use App\Models\ProductVariation;

/**
 * The one place a variation's shipping weight and dimensions are decided.
 *
 * `product_variations.weight_g` and the three `*_mm` columns are nullable
 * overrides — null means inherit the product's, exactly as
 * `ResolveVariationPrice` treats `price`. Reading `$variation->weight_g`
 * directly is the bug this class exists to prevent: it returns null for the
 * overwhelmingly common variation that simply weighs what its product
 * weighs, and a null handed to a courier is a zero-weight parcel rather
 * than an error.
 *
 * **Inheritance is per axis, not all-or-nothing.** A hardcover edition is
 * the same page size as its paperback sibling and only thicker; a 5Ah
 * battery is the same footprint as the 2Ah and only taller. Forcing a
 * variation that overrides one axis to restate the other two would mean
 * copying values that are genuinely identical, and every copy is a chance
 * for them to drift apart from the product they were copied from.
 *
 * Returns null when neither level records a figure — legal, since every one
 * of these columns is nullable on `products` too. A caller that needs a
 * number (a courier quote) checks for null and refuses; a caller that only
 * displays it renders nothing.
 *
 * A function, not an Action: it writes nothing, the same reasoning that
 * keeps `ResolveVariationPrice` and `CalculateCartTotals` out of
 * `app/Actions`. `reference/write-rules/product.md`
 */
final class ResolveVariationMeasurements
{
    /** Whole grams, or null when neither the variation nor its product records one. */
    public static function weightGrams(ProductVariation $variation): ?int
    {
        return $variation->weight_g ?? self::product($variation)->weight_g;
    }

    /**
     * Whole millimetres per axis, each resolved independently.
     *
     * @return array{length: ?int, width: ?int, height: ?int}
     */
    public static function dimensionsMillimetres(ProductVariation $variation): array
    {
        $product = self::product($variation);

        return [
            'length' => $variation->length_mm ?? $product->length_mm,
            'width' => $variation->width_mm ?? $product->width_mm,
            'height' => $variation->height_mm ?? $product->height_mm,
        ];
    }

    /**
     * True when every figure a courier needs to quote a parcel is present.
     * Checked as a set because a quote needs all four or none of them is
     * useful, which is a different question from how each one is resolved.
     */
    public static function isShippable(ProductVariation $variation): bool
    {
        if (self::weightGrams($variation) === null) {
            return false;
        }

        return ! in_array(null, self::dimensionsMillimetres($variation), true);
    }

    private static function product(ProductVariation $variation): Product
    {
        /** @var Product $product */
        $product = $variation->product;

        return $product;
    }
}
