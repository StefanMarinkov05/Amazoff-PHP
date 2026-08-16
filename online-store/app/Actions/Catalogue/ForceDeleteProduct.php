<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductCannotBeErasedException;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Erases a product permanently, children before parent.
 *
 * Every data-carrying child of `products` is a `NO ACTION` foreign key, so
 * deleting the product first is always error 1451.
 *
 * Refused where something must outlive it. Order lines are the trap:
 * `order_items.product_id` is `ON DELETE SET NULL`, so the database would
 * accept the erase and silently null the reference, which §19 forbids.
 *
 * Soft-deletes the product before erasing its variations, because
 * `ForceDeleteProductVariation` refuses to erase the last variation of an
 * available product — moot when the whole product is going.
 *
 * Authorizes `delete_product`. Locks `products`, then `inventories`.
 * reference/product-write-rules.md
 */
final class ForceDeleteProduct
{
    public function __construct(private readonly ForceDeleteProductVariation $eraseVariation) {}

    /**
     * @throws ProductCannotBeErasedException
     */
    public function handle(Product $product, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $product);
        }

        DB::transaction(function () use ($product, $actor): void {
            Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            $orderItems = OrderItem::query()->where('product_id', $product->getKey())->count();

            if ($orderItems > 0) {
                throw ProductCannotBeErasedException::isOrdered($product, $orderItems);
            }

            $reviews = $product->productReviews()->count();

            if ($reviews > 0) {
                throw ProductCannotBeErasedException::isReviewed($product, $reviews);
            }

            $wishlisted = WishlistItem::query()->where('product_id', $product->getKey())->count();

            if ($wishlisted > 0) {
                throw ProductCannotBeErasedException::isWishlisted($product, $wishlisted);
            }

            // Cart lines go with the variations, for the reason given in
            // ForceDeleteProductVariation: a cart is transient state, not
            // referential integrity.
            CartItem::query()
                ->whereIn('product_variation_id', ProductVariation::withTrashed()
                    ->where('product_id', $product->getKey())
                    ->select('id'))
                ->delete();

            // withTrashed(): a soft-deleted variation still holds the foreign
            // key, so the scope would hide exactly the rows that cause 1451.
            ProductVariation::withTrashed()
                ->where('product_id', $product->getKey())
                ->get()
                ->each(fn (ProductVariation $variation) => $this->eraseVariation->handle(
                    $variation,
                    $actor,
                    productIsBeingErased: true,
                ));

            // Images last of the two: product_variations.image_id references
            // them, so they can only go once every variation has.
            $product->productSpecifications()->delete();
            $product->productImages()->delete();

            $product->forceDelete();
        });
    }
}
