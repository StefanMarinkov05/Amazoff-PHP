<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ImageNotOnProductException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets a variation's whole image gallery, in order, in one write.
 *
 * The contested state here is the *ordered list*, not any single membership,
 * so one Action owns the whole set — the same reasoning `SetMainProductImage`
 * gives for owning "exactly one main image" across a product's images. That
 * shape is what dissolves the races a three-Action attach/detach/reorder split
 * would have had: adding while another administrator removes, reordering while
 * another detaches, and two simultaneous reorders are all the same operation
 * here — two full-set writes, serialised by the lock, the later one winning
 * wholesale. An administrator's edit can therefore be overwritten silently
 * within one page-load window, which is accepted for a display ordering on the
 * same reasoning `write-rules/product.md` accepts it for main-image promotion.
 *
 * Positions are written contiguously from 1 because the whole set is rewritten
 * every time; nothing renumbers after the fact because nothing writes a
 * partial set. `position` still carries no unique index — `sync()` updates
 * pivot rows one statement at a time, so swapping two images would collide
 * transiently, and MySQL has no deferrable constraints to defer the check to
 * commit. ADR-0013 records the trade.
 *
 * Authorizes `update_product_variation` via `ProductVariationPolicy`. Locks
 * `products` — the same row, in the same order, as `RemoveProductImage` and
 * `ForceDeleteProductVariation`, which is what keeps the three from
 * deadlocking against each other.
 * ADR-0008 · reference/write-rules/product-variation-images.md
 */
final class SetVariationImages
{
    /**
     * @param  list<int>  $imageIds  Ordered. Array index becomes `position`;
     *                               an empty list clears the gallery. Duplicates
     *                               are collapsed to their first occurrence.
     *
     * @throws RemovedFromCatalogueException
     * @throws ImageNotOnProductException
     */
    public function handle(ProductVariation $variation, array $imageIds, ?User $actor): ProductVariation
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $variation);
        }

        return DB::transaction(function () use ($variation, $imageIds): ProductVariation {
            Product::query()
                ->whereKey($variation->product_id)
                ->lockForUpdate()
                ->first();

            // Re-read through the SoftDeletes scope rather than trusting the
            // instance the caller holds: a Filament page can have been open
            // since before the variation was removed, and sync() would happily
            // build a gallery for a row nobody can reach.
            $live = ProductVariation::query()->whereKey($variation->getKey())->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::variation($variation);
            }

            // array_unique keeps the first occurrence, which is the position
            // the administrator actually chose for it.
            $imageIds = array_values(array_unique(array_map(intval(...), $imageIds)));

            $this->assertImagesBelongToProduct($live, $imageIds);

            $payload = [];

            foreach ($imageIds as $index => $imageId) {
                $payload[$imageId] = ['position' => $index + 1];
            }

            $live->images()->sync($payload);

            return $live->load('images');
        });
    }

    /**
     * @param  list<int>  $imageIds
     *
     * @throws ImageNotOnProductException
     */
    private function assertImagesBelongToProduct(ProductVariation $variation, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        /** @var list<int> $owned */
        $owned = ProductImage::query()
            ->where('product_id', $variation->product_id)
            ->whereIn('id', $imageIds)
            ->pluck('id')
            ->all();

        $foreign = array_values(array_diff($imageIds, $owned));

        if ($foreign !== []) {
            throw new ImageNotOnProductException($variation, $foreign);
        }
    }
}
