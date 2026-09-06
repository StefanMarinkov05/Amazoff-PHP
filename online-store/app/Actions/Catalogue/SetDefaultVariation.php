<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Promotes one variation to default and demotes the rest, in one `UPDATE`.
 *
 * A product with variations has exactly one default — the variation a
 * listing card, and any other single-variation summary, presents the product
 * as. MySQL cannot express "exactly one" with a partial unique index, so
 * this is an application invariant, the same shape `SetMainProductImage`
 * already owns for `product_images.is_main`. `products.default_variation_id`
 * was considered and rejected on the same reasoning that settled `is_main`:
 * the flag belongs on the child it describes, not as a second door on the
 * parent.
 *
 * Takes no lock, for the identical reason `SetMainProductImage` takes none:
 * it reads nothing to decide anything, so there is no check-then-act window,
 * and one statement cannot deadlock the way a demote-then-promote pair can.
 *
 * Authorizes `update_product_variation` via `ProductVariationPolicy`. Locks
 * nothing.
 * reference/write-rules/product.md
 */
final class SetDefaultVariation
{
    public function handle(ProductVariation $variation, ?User $actor): ProductVariation
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $variation);
        }

        $variationKey = $variation->getKey();

        if (! is_int($variationKey)) {
            throw new InvalidArgumentException('ProductVariation::getKey() returned a non-integer value.');
        }

        // `is_default = (id = N)` evaluates per row: true for this variation,
        // false for its siblings. The cast to int is what makes the raw
        // fragment injection-safe; nothing else here is interpolated.
        ProductVariation::query()
            ->where('product_id', $variation->product_id)
            // @phpstan-ignore argument.type (validated int above, not a literal-string but injection-safe)
            ->update(['is_default' => DB::raw('`id` = '.$variationKey)]);

        return $variation->refresh();
    }
}
