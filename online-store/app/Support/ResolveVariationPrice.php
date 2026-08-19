<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Carbon;

/**
 * The one place §11's price rule is decided: current, never stored.
 *
 * `product_variations.price`/`discount_price` are nullable overrides —
 * null means inherit the product's. The discount *window* exists only on
 * `Product`, so a variation's own `discount_price` is still gated by the
 * product's `discount_starts_at`/`ends_at`: one window per product,
 * variations override the amount, not the schedule.
 *
 * Called by `AddToCart`/`UpdateCartItemQuantity` and, later, `CreateOrder` —
 * a function, not an Action, because it writes nothing.
 * `reference/write-rules/product.md`
 */
final class ResolveVariationPrice
{
    public static function current(ProductVariation $variation): string
    {
        /** @var Product $product */
        $product = $variation->product;

        $base = (string) ($variation->price ?? $product->regular_price);
        $discount = $variation->discount_price ?? $product->discount_price;

        if ($discount === null || ! self::windowActive($product)) {
            return $base;
        }

        return (string) $discount;
    }

    private static function windowActive(Product $product): bool
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
