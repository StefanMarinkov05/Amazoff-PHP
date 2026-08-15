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
 * Removes a variation, refusing the two removals that break something.
 *
 * The second door on §6–7's invariant. `CreateProduct` and `UpdateProduct`
 * guard the doors where a product gains or publishes; this guards the one
 * where it loses. Without it the invariant was enforced at two of three
 * entrances, which is not enforcement — deleting the only variation of an
 * available product required no concurrency, no unusual timing, and produced
 * no complaint from anything.
 *
 * ## Why it locks the product rather than the variation
 *
 * Both refusals are check-then-act: count the variations, then delete; read
 * the reserved quantity, then delete. Between the read and the write another
 * request can publish the product or reserve the last unit, and the decision
 * was made on state that is no longer true.
 *
 * A transaction alone does not close it. Under `REPEATABLE READ` the count is
 * served from the MVCC snapshot taken at the transaction's first read, so it
 * stays stale by construction and the DELETE commits anyway — the same
 * mechanism `explanation/concurrency-and-locking.md` describes for stock.
 *
 * So the lock is on `products`, the aggregate root, and `UpdateProduct` takes
 * the same one. That is what makes the pair safe in both interleavings:
 *
 *   publish first    this Action then sees an available product with one
 *                    variation and refuses
 *   remove first     UpdateProduct then sees zero variations and refuses
 *
 * Locking the variation row instead would not work: the two Actions would
 * contend on different rows and neither would wait for the other.
 *
 * ## Lock order
 *
 * `products` before `inventories`, always. `ReserveStock` and `ReleaseStock`
 * take `inventories` alone and never reach for a product, so there is no cycle
 * to deadlock on. Any future Action taking both acquires in this order.
 */
final class RemoveProductVariation
{
    /**
     * The inventory row is deliberately left behind. It is soft-deletion of
     * the variation, not of its history: `inventory_movements` hangs off the
     * inventory row, and §20's ledger is what makes a past quantity
     * explainable. A restored variation finds its stock where it left it.
     *
     * @throws ProductRequiresVariationException
     * @throws VariationHasReservedStockException
     */
    public function handle(ProductVariation $variation, ?User $actor = null): ProductVariation
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $variation);
        }

        return DB::transaction(function () use ($variation): ProductVariation {
            // Taken for the lock, not for the value. The row is already in
            // $variation->product; what this adds is that no other transaction
            // may read-for-update or write this product until we commit.
            $product = Product::query()
                ->whereKey($variation->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->first();

            if ($inventory !== null && $inventory->reserved_quantity > 0) {
                throw new VariationHasReservedStockException($variation, $inventory->reserved_quantity);
            }

            // Counted through the SoftDeletes global scope, so an
            // already-trashed sibling does not keep the product alive.
            if ($product->is_available && $product->productVariations()->count() === 1) {
                throw ProductRequiresVariationException::whenLastVariationRemoved($product);
            }

            $variation->delete();

            return $variation;
        });
    }
}
