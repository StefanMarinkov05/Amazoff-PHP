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
 * Erases a variation permanently, together with the stock row that exists only
 * for it — and refuses whenever something still depends on either.
 *
 * `inventories.product_variation_id` is a `NO ACTION` foreign key and every
 * variation created through `AddProductVariation` has a row, so deleting the
 * variation first is always error 1451. That is not a hypothetical: it is what
 * Filament's default `ForceDeleteAction` does, verified against the running
 * database. Ordering the two deletes correctly is the whole mechanical part of
 * this Action; the rest is deciding when it is allowed at all.
 *
 * ## Erasing is not deleting
 *
 * `RemoveProductVariation` soft-deletes: the variation leaves the catalogue and
 * every row explaining it survives, which is what §19 and §20 require. This
 * destroys them, so it is legal only where there is nothing to destroy — a
 * variation created by mistake, never stocked, never ordered, never carted.
 *
 * The order-line check is the one worth reading twice.
 * `order_items.product_variation_id` is `ON DELETE SET NULL`, so the database
 * would *allow* this and quietly null the reference. A refusal is better than
 * a success nobody is told about.
 *
 * ## Locking
 *
 * Same aggregate root and same order as `RemoveProductVariation` — `products`,
 * then `inventories` — so the three Actions that can break §6–7's invariant
 * all serialise against each other.
 */
final class ForceDeleteProductVariation
{
    /**
     * There is no `forceDelete_product_variation` permission — see
     * `reference/permissions.md`, which records that `restore` and
     * `forceDelete` abilities do not exist. Erasing is authorized as `delete`,
     * the destructive ability that does exist.
     *
     * @throws VariationCannotBeErasedException
     * @throws VariationHasReservedStockException
     * @throws ProductRequiresVariationException
     */
    public function handle(ProductVariation $variation, ?User $actor = null): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $variation);
        }

        DB::transaction(function () use ($variation): void {
            $product = Product::query()
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

            $cartItems = CartItem::query()
                ->where('product_variation_id', $variation->getKey())
                ->count();

            if ($cartItems > 0) {
                throw VariationCannotBeErasedException::isInCart($variation, $cartItems);
            }

            // The §6–7 invariant applies to erasing as it does to removing,
            // but the question is not "how many variations are there" — it is
            // "how many will still be sellable afterwards".
            //
            // Counting all of them and comparing to 1 gets this wrong in both
            // directions, because `productVariations()` runs through the
            // SoftDeletes scope. Erasing an already-trashed variation does not
            // change the live count at all and would be refused for no reason;
            // erasing a live one reduces it by one. Counting the *others* is
            // the same question for both cases.
            $otherLiveVariations = $product->productVariations()
                ->whereKeyNot($variation->getKey())
                ->count();

            if ($product->is_available && $otherLiveVariations === 0) {
                throw ProductRequiresVariationException::whenLastVariationRemoved($product);
            }

            // Child before parent. The reverse is error 1451.
            $inventory?->delete();

            $variation->forceDelete();
        });
    }
}
