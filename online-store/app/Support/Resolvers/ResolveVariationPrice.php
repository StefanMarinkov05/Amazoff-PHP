<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariation;

/**
 * The one place §11's variation price rule is decided: current, never stored.
 *
 * `product_variations.price`/`discount_price` are nullable overrides —
 * null means inherit the product's. The discount *window* exists only on
 * `Product`, so a variation's own `discount_price` is still gated by the
 * product's `discount_starts_at`/`ends_at`: one window per product,
 * variations override the amount, not the schedule. The window itself is
 * `ResolveProductPrice::windowActive()`, shared rather than duplicated.
 *
 * Called by `AddToCart`/`UpdateCartItemQuantity` and, later, `CreateOrder` —
 * a function, not an Action, because it writes nothing.
 * `reference/write-rules/product.md`
 */
final class ResolveVariationPrice
{
    /** The price to charge. What the cart and the order line use. */
    public static function current(ProductVariation $variation): string
    {
        return self::detailed($variation)->current;
    }

    /**
     * The same resolution, with the parts a page needs to render it — the
     * struck-through original and the saving alongside the price to charge.
     *
     * Same return type as `ResolveProductPrice::current()`, so a template can
     * treat a product's price and a variation's price identically.
     */
    public static function detailed(ProductVariation $variation): ProductPrice
    {
        /** @var Product $product */
        $product = $variation->product;

        $discount = $variation->discount_price ?? $product->discount_price;

        return ProductPrice::make(
            regular: (string) ($variation->price ?? $product->regular_price),
            discount: $discount === null ? null : (string) $discount,
            windowOpen: ResolveProductPrice::windowActive($product),
        );
    }
}
