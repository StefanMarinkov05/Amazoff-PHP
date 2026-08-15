<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductCannotBeErasedException;
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
 * deleting the product first is always error 1451 — which is what Filament's
 * default `ForceDeleteAction` does on the product edit page today. Same defect
 * and same fix as `ForceDeleteProductVariation`, one level up.
 *
 * Refuses wherever something must outlive the record. Order lines are the trap:
 * `order_items.product_id` is `ON DELETE SET NULL`, so the database would
 * accept the erase and silently null the reference, which §19 forbids.
 *
 * Soft-deletes the product before erasing its variations, because
 * `ForceDeleteProductVariation` refuses to erase the last variation of an
 * *available* product — a rule that is moot when the whole product is going.
 *
 * `reference/product-write-rules.md` has the full outcome table.
 */
final class ForceDeleteProduct
{
    public function __construct(private readonly ForceDeleteProductVariation $eraseVariation) {}

    /**
     * @throws ProductCannotBeErasedException
     */
    public function handle(Product $product, ?User $actor = null): void
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

            // Soft-delete the product first: ForceDeleteProductVariation
            // refuses to erase the last variation of an *available* product,
            // and that rule is moot when the whole product is going.
            $product->delete();

            // withTrashed(): a soft-deleted variation still holds the foreign
            // key, so the SoftDeletes scope would hide exactly the rows that
            // cause 1451. Delegated so the ledger refusal lives in one place.
            ProductVariation::withTrashed()
                ->where('product_id', $product->getKey())
                ->get()
                ->each(fn (ProductVariation $variation) => $this->eraseVariation->handle($variation, $actor));

            // Images last of the two: product_variations.image_id references
            // them, so they can only go once every variation has.
            $product->productSpecifications()->delete();
            $product->productImages()->delete();

            $product->forceDelete();
        });
    }
}
