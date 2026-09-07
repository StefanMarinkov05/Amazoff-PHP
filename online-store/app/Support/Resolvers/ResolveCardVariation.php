<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Collection;

/**
 * Which variation a catalogue card represents — the one whose price and
 * discount badge a shopper sees before opening the product.
 *
 * Same shape as `ResolveVariationImage`/`ResolveVariationPrice`: resolved at
 * read time from the variations already on hand, never stored. A function,
 * not an Action, because it writes nothing.
 *
 * The default variation (`is_default`) is the natural first choice — it is
 * what `ProductDetails` opens to. But a card advertising a variation nobody
 * can currently buy is worse than showing a real alternative: reported live,
 * a default variation genuinely out of stock left the card showing "Out of
 * stock" while siblings sitting one click away were buyable, several on
 * sale. Preferring **any** buyable variation over an unbuyable default, and
 * among buyable ones the one with the deepest discount, is what a shopper
 * skimming a grid actually wants surfaced first — the same reasoning that
 * puts a percentage badge on the card at all.
 *
 * "Buyable" mirrors `ProductList::availableStock()`: `is_available` and
 * `available() > 0` (current minus reserved) — a unit held for someone
 * mid-checkout is not one this shopper can buy either.
 */
final class ResolveCardVariation
{
    /**
     * @return ProductVariation|null `null` only when the product has no
     *                               variations at all, which `AddProductVariation`'s
     *                               own invariant makes unreachable for a
     *                               real product but not for one built by
     *                               hand in a test.
     */
    public static function current(Product $product): ?ProductVariation
    {
        /** @var Collection<int, ProductVariation> $variations */
        $variations = $product->productVariations;

        $buyable = $variations->filter(self::isBuyable(...));

        if ($buyable->isEmpty()) {
            return $variations->firstWhere('is_default', true) ?? $variations->first();
        }

        $default = $buyable->firstWhere('is_default', true);

        if ($default !== null && self::isBuyable($default)) {
            return $default;
        }

        // Biggest discount first — the same `percent` a card's own badge
        // shows, so the variation chosen to represent the product is never
        // out of step with the number displayed for it. A tie keeps
        // Eloquent's own order (id, ordinarily creation order) rather than
        // introducing a second one nothing asks for.
        return $buyable
            ->sortByDesc(fn (ProductVariation $variation): int => ResolveVariationPrice::detailed($variation)->percent)
            ->first();
    }

    private static function isBuyable(ProductVariation $variation): bool
    {
        if (! $variation->is_available) {
            return false;
        }

        $inventory = $variation->inventory;

        // Larastan types hasOne as non-null, and every Action that creates a
        // variation creates its stock row in the same transaction — but a
        // row written outside them would not, and a catalogue page is the
        // wrong place to fatal over it. The same guard availableStock() uses.
        if (! $inventory instanceof Inventory) {
            return false;
        }

        return $inventory->available() > 0;
    }
}
