<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductRequiresVariationException;
use App\Exceptions\VariationCannotBeErasedException;
use App\Exceptions\VariationHasReservedStockException;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Erases a variation and the stock row that exists only for it.
 *
 * The stock row goes first: `inventories.product_variation_id` is `NO ACTION`,
 * so the reverse order is error 1451 — which is what Filament's default
 * `ForceDeleteAction` still does elsewhere.
 *
 * Refused whenever something must outlive it: a §20 ledger, an order line, or
 * held stock. Cart lines are deleted instead, not refused.
 *
 * Authorizes `delete_product_variation` (no `forceDelete` ability exists).
 * Locks `products`, then `inventories`.
 * ADR-0008 · reference/product-write-rules.md · reference/permissions.md
 */
final class ForceDeleteProductVariation
{
    /**
     * Authorized as `delete`: no `forceDelete` ability exists, per
     * `reference/permissions.md`.
     *
     * @param  bool  $productIsBeingErased  Set only by `ForceDeleteProduct`.
     *                                      Waives the last-variation refusal,
     *                                      which protects a sellable product
     *                                      that is not about to stop existing.
     *
     * @throws VariationCannotBeErasedException
     * @throws VariationHasReservedStockException
     * @throws ProductRequiresVariationException
     */
    public function handle(
        ProductVariation $variation,
        ?User $actor,
        bool $productIsBeingErased = false,
    ): void {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $variation);
        }

        DB::transaction(function () use ($variation, $productIsBeingErased): void {
            // withTrashed(): ForceDeleteProduct erases variations after the
            // product is soft-deleted, and firstOrFail() through the
            // SoftDeletes scope would raise ModelNotFoundException there.
            $product = Product::withTrashed()
                ->whereKey($variation->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->first();

            if ($inventory !== null) {
                if ($inventory->reserved_quantity > 0) {
                    throw new VariationHasReservedStockException($variation, $inventory->reserved_quantity);
                }

                $movements = $inventory->inventoryMovements()->count();

                if ($movements > 0) {
                    throw VariationCannotBeErasedException::hasLedger($variation, $movements);
                }
            }

            $orderItems = OrderItem::query()
                ->where('product_variation_id', $variation->getKey())
                ->count();

            if ($orderItems > 0) {
                throw VariationCannotBeErasedException::isOrdered($variation, $orderItems);
            }

            // Dropped, not refused: a cart is transient state, not
            // referential integrity. cart_items is NO ACTION, so the row must
            // go before the variation either way.
            CartItem::query()
                ->where('product_variation_id', $variation->getKey())
                ->delete();

            // Count the *others*, not all-minus-one: productVariations() runs
            // through the SoftDeletes scope, so comparing the total to 1 would
            // wrongly refuse erasing an already-trashed variation.
            $otherLiveVariations = $product->productVariations()
                ->whereKeyNot($variation->getKey())
                ->count();

            // §6–7's invariant, unless the product is going too — then there
            // is no sellable product left for it to protect.
            if (! $productIsBeingErased && $product->is_available && $otherLiveVariations === 0) {
                throw ProductRequiresVariationException::whenLastVariationRemoved($product);
            }

            // Child before parent. The reverse is error 1451.
            $inventory?->delete();

            $variation->forceDelete();
        });
    }
}
