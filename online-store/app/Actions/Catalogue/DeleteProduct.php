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
 * The cascade lives here rather than in the schema because a database-level
 * one could not stop at variations — it would reach `inventory_movements` and
 * destroy §20's ledger. Trashing the variations is what makes them
 * unreservable, since `ReserveStock` checks the variation, not the product.
 *
 * Restoring is not the inverse: Laravel does not record which children a
 * cascade trashed, so a restored product keeps trashed variations and
 * `UpdateProduct` refuses to publish it until one is restored.
 *
 * Authorizes `delete_product`. Locks `products`.
 * reference/product-write-rules.md
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
