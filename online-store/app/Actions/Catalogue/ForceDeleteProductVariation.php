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
 * Erases a variation and the stock row that exists only for it, refusing
 * whenever something still depends on either.
 *
 * The stock row goes first: `inventories.product_variation_id` is `NO ACTION`,
 * so the reverse order is error 1451 — what Filament's default
 * `ForceDeleteAction` does today.
 *
 * Legal only where nothing has to outlive it: a stock ledger (§20), an order
 * line, or held stock. `order_items` is `ON DELETE SET NULL`, so the database
 * would accept the erase and quietly null the reference, which §19 forbids.
 * Cart lines are deleted rather than refused — see below. Full outcome table
 * in `reference/product-write-rules.md`.
 *
 * Locks `products` then `inventories`, the same order as every Action that can
 * move §6–7's invariant. ADR-0008.
 *
 * Authorizes `delete_product_variation` — no `forceDelete` ability exists, per
 * `reference/permissions.md`. Locks `products`, then `inventories`. See
 * ADR-0008 and `reference/product-write-rules.md`.
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
        ?User $actor = null,
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

            // Cart lines are dropped, not refused. A cart is transient
            // self-repairing state — it has an expires_at and no historical
            // value — so blocking a catalogue operation on one would let any
            // customer pin a row indefinitely by leaving a tab open. The
            // customer would have lost the line at checkout anyway; deleting
            // it here resolves that instead of moving the failure onto the
            // administrator. cart_items is NO ACTION, so the row has to go
            // before the variation regardless.
            //
            // The dangerous case is still covered: a cart that reached
            // checkout holds a *reservation*, and reserved stock is refused
            // above.
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
