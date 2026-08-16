<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Promotes one image to main and demotes the rest, in one `UPDATE`.
 *
 * A product with images has exactly one main image, which MySQL cannot express
 * — no partial unique index — so it is an application invariant. Owns that rule
 * for the whole set; `AddProductImage` and `RemoveProductImage` compose this.
 *
 * Takes no lock, unlike the rest of this namespace: it reads nothing to decide
 * anything, so there is no check-then-act window. One statement also cannot
 * deadlock the way a demote-then-promote pair can.
 *
 * Authorizes `update_product` via `ProductImagePolicy`. Locks nothing.
 * explanation/concurrency-and-locking.md · reference/product-write-rules.md
 */
final class SetMainProductImage
{
    public function handle(ProductImage $image, ?User $actor = null): ProductImage
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $image);
        }

        // `is_main = (id = N)` evaluates per row: true for this image, false
        // for its siblings. The cast to int is what makes the raw fragment
        // injection-safe; nothing else here is interpolated.
        ProductImage::query()
            ->where('product_id', $image->product_id)
            ->update(['is_main' => DB::raw('`id` = '.(int) $image->getKey())]);

        return $image->refresh();
    }
}
