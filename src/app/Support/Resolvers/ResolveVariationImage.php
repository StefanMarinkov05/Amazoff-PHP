<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariation;

/**
 * The one place "which image represents this variation" is decided.
 *
 * Same shape as `ResolveVariationPrice`: a variation overrides the product or
 * inherits from it, resolved at read time and never stored. A function, not an
 * Action, because it writes nothing.
 *
 * A variation's own gallery wins; otherwise it inherits the product's main
 * image, which is what a listing is already showing. Null only when the
 * product has no images at all — legal, and `write-rules/product.md` records
 * it as such.
 *
 * `reference/write-rules/product-variation-images.md`
 */
final class ResolveVariationImage
{
    /** Checked into `public/`, not any storage disk — it ships with the app, an admin never uploads it. */
    public const DEFAULT_PATH = 'images/default-product.png';

    /**
     * `current()`'s result, resolved to a servable URL — the placeholder
     * asset when there is no image at all, never `null`.
     *
     * A separate method rather than a `?ProductImage` overload: `current()`'s
     * contract — a model or `null`, `null` being legal and documented — is
     * relied on by callers that need to *know* nothing exists, e.g. an admin
     * screen deciding whether to show "no image" as a fact rather than paper
     * over it. This one is for callers that only ever want something to
     * render.
     */
    public static function urlOrDefault(ProductVariation $variation): string
    {
        $image = self::current($variation);

        // No row at all, or a row whose file is not actually on disk — both
        // fall back the same way. `servableUrl()` is what does the second
        // check; a row existing was never a guarantee the file behind it
        // does, confirmed live by a row pointing at a path that was never
        // written and every caller of this method rendering a broken image
        // until this file-existence check was added.
        return $image?->servableUrl() ?? asset(self::DEFAULT_PATH);
    }

    public static function current(ProductVariation $variation): ?ProductImage
    {
        /** @var ProductImage|null $own */
        $own = $variation->images()->first();

        if ($own !== null) {
            return $own;
        }

        /** @var Product|null $product */
        $product = $variation->product;

        if ($product === null) {
            return null;
        }

        /** @var ProductImage|null $main */
        $main = $product->productImages()->where('is_main', true)->first();

        return $main;
    }
}
