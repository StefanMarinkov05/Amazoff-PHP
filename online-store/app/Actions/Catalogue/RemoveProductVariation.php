<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductRequiresVariationException;
use App\Exceptions\VariationHasReservedStockException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Soft-deletes a variation, refusing the two removals that break something.
 *
 * The third door on §6–7's invariant, alongside `CreateProduct` and
 * `UpdateProduct`. Guarding only some of the doors is not guarding it.
 *
 * Locks the `products` row — the aggregate root — so this and `UpdateProduct`
 * serialise; locking the variation would leave them contending on different
 * rows and waiting for nothing. Lock order is `products` then `inventories`,
 * globally. ADR-0008, and `reference/product-write-rules.md` for outcomes.
 *
 * The inventory row is left behind on purpose: `inventory_movements` hangs off
 * it and §20's ledger has to survive a removal.
 */
final class RemoveProductVariation
{
    /**
     * @throws ProductRequiresVariationException
     * @throws VariationHasReservedStockException
     */
    public function handle(ProductVariation $variation, ?User $actor = null): ProductVariation
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $variation);
        }

        return DB::transaction(function () use ($variation): ProductVariation {
            $product = Product::query()
                ->whereKey($variation->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->first();

            // Removing it would strand the reservation: still subtracted from
            // available(), on a row nothing lists.
            if ($inventory !== null && $inventory->reserved_quantity > 0) {
                throw new VariationHasReservedStockException($variation, $inventory->reserved_quantity);
            }

            // Counted through the SoftDeletes scope, so a trashed sibling does
            // not keep the product alive.
            if ($product->is_available && $product->productVariations()->count() === 1) {
                throw ProductRequiresVariationException::whenLastVariationRemoved($product);
            }

            $variation->delete();

            return $variation;
        });
    }
}
