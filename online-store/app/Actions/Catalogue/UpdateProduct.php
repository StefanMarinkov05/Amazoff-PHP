<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductRequiresVariationException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Updates a product's own columns, refusing an edit that would leave it
 * sellable with nothing to sell (§6–7).
 *
 * The check is check-then-act and `RemoveProductVariation` guards the other
 * side, so both lock the `products` row — the aggregate root. A transaction
 * alone would not close it: the count comes from the MVCC snapshot and the
 * UPDATE commits anyway.
 *
 * Does **not** protect against two employees overwriting each other's fields
 * across two requests. That window is human think time, which no lock spans.
 *
 * Authorizes `update_product`. Locks `products`.
 * ADR-0008 · reference/product-write-rules.md
 */
final class UpdateProduct
{
    /**
     * @param  array<string, mixed>  $attributes  Product columns to change.
     *
     * @throws ProductRequiresVariationException
     * @throws RemovedFromCatalogueException
     */
    public function handle(Product $product, array $attributes, ?User $actor): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $product);
        }

        return DB::transaction(function () use ($product, $attributes): Product {
            // Taken for the lock, not for the value. first() rather than
            // firstOrFail() so the soft-delete case is answered deliberately
            // rather than as a 404 side effect of the SoftDeletes scope.
            $live = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::product($product);
            }

            $product->fill($attributes);

            if ($product->is_available && $product->productVariations()->count() === 0) {
                throw ProductRequiresVariationException::whenMadeAvailable($product);
            }

            $product->save();

            return $product;
        });
    }
}
