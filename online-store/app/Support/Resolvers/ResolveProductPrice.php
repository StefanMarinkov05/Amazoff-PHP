<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * What price does this product show, before a variation has been picked?
 *
 * The product-level counterpart to `ResolveVariationPrice`, and the one place
 * §11's discount *window* is decided — `ResolveVariationPrice` calls
 * `windowActive()` here rather than keeping its own copy, so a catalogue card
 * and a cart line can never disagree about whether a sale is running.
 *
 * A function, not an Action, because it writes nothing. ADR-0014.
 */
final class ResolveProductPrice
{
    public static function current(Product $product): ProductPrice
    {
        return ProductPrice::make(
            regular: (string) $product->regular_price,
            discount: $product->discount_price === null ? null : (string) $product->discount_price,
            windowOpen: self::windowActive($product),
        );
    }

    /**
     * Is the product's discount schedule live right now?
     *
     * A null boundary means "no limit on that side", so a discount with
     * neither date set is permanently active. The window lives on the product
     * even for variation pricing: §11 lets a variation override the discount
     * *amount*, never the schedule.
     */
    public static function windowActive(Product $product): bool
    {
        $now = Carbon::now();

        if ($product->discount_starts_at !== null && $now->lt($product->discount_starts_at)) {
            return false;
        }

        if ($product->discount_ends_at !== null && $now->gt($product->discount_ends_at)) {
            return false;
        }

        return true;
    }
}
