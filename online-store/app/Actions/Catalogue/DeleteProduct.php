<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Soft-deletes a product and its variations.
 *
 * The cascade lives here rather than in the schema on purpose: `CASCADE` in
 * this database is reserved for pure join tables, and a database-level cascade
 * could not stop at variations — it would reach `inventory_movements` and
 * destroy §20's ledger. See `reference/product-write-rules.md`.
 *
 * Trashing the variations is what makes them unreservable; `ReserveStock`
 * checks the variation, not the product.
 *
 * Restoring is not the inverse. Laravel does not record which children a
 * cascade trashed, so a restore leaves variations trashed and `UpdateProduct`
 * refuses to publish the product until one is restored explicitly.
 */
final class DeleteProduct
{
    public function handle(Product $product, ?User $actor = null): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $product);
        }

        return DB::transaction(function () use ($product): Product {
            Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            // Reserved stock is deliberately not a refusal here, unlike
            // RemoveProductVariation: delisting a product while orders are
            // open is ordinary, and refusing would force an administrator to
            // cancel live orders first.
            $product->productVariations()->each(
                static fn (object $variation): mixed => $variation->delete(),
            );

            $product->delete();

            return $product;
        });
    }
}
