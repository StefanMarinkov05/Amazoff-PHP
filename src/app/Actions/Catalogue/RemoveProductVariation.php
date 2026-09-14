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
 * Locks the aggregate root rather than the variation, so this and
 * `UpdateProduct` serialise; locking each Action's own target would leave them
 * contending on different rows and waiting for nothing.
 *
 * The inventory row is left behind: `inventory_movements` hangs off it and
 * §20's ledger has to survive a removal.
 *
 * Hands the default flag on to a live sibling if the removed variation held
 * it — the same successor-promotion `RemoveProductImage` does for `is_main`,
 * so a product never ends up with variations and no default. If none remain
 * the last-variation refusal above has already fired, so this only ever runs
 * when a sibling exists to receive it.
 *
 * Authorizes `delete_product_variation`. Locks `products`, then `inventories`.
 * ADR-0008 · reference/write-rules/product.md
 */
final class RemoveProductVariation
{
    public function __construct(private readonly SetDefaultVariation $setDefault) {}

    /**
     * @throws ProductRequiresVariationException
     * @throws VariationHasReservedStockException
     */
    public function handle(ProductVariation $variation, ?User $actor): ProductVariation
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

            $wasDefault = (bool) $variation->is_default;

            $variation->delete();

            if ($wasDefault) {
                /** @var ProductVariation|null $successor */
                $successor = $product->productVariations()->first();

                if ($successor !== null) {
                    // null, not $actor — same reasoning AddProductVariation
                    // gives at its own setDefault call: this Action's gate
                    // already authorized delete_product_variation for the
                    // whole write, and successor-promotion is a structural
                    // consequence of that delete, not a second discretionary
                    // edit that should additionally demand
                    // update_product_variation.
                    $this->setDefault->handle($successor, null);
                }
            }

            return $variation;
        });
    }
}
