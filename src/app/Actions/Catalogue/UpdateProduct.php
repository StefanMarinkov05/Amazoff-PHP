<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\AttributeNotAllowedForCategoryException;
use App\Exceptions\ProductRequiresVariationException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Resolvers\ResolveAllowedAttributes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Updates a product's own columns, refusing an edit that would leave it
 * sellable with nothing to sell (§6–7).
 *
 * The check is check-then-act and `RemoveProductVariation` guards the other
 * side, so both lock the `products` row — the aggregate root. A transaction
 * alone would not close it: the count comes from the MVCC snapshot and the
 * UPDATE commits anyway.
 *
 * Does **not** protect against two employees overwriting each other's fields
 * across two requests. That window is human think time, which no lock spans.
 *
 * Also syncs the product's own variation axes (`attributes`, the `Product` ↔
 * `Attribute` pivot `ProductForm` labels "Variation axes") when the caller
 * supplies one, for the same reason `CreateProduct` does: `ProductForm`'s
 * field is a plain `->options()` Select rather than `->relationship()`, so
 * nothing else attaches it. An admin narrowing the axes here — dropping
 * "Volume" from a product that no longer varies by it — does not retroactively
 * touch any variation's existing combination; a variation left holding a
 * value from a now-removed axis only surfaces the next time
 * `SetVariationAttributeValues` runs against it.
 *
 * Also refuses a variation axis `attribute_product_category` does not allow
 * for the product's own category — checked *after* `save()`, using whatever
 * category the product has now, since this same call can change it. An
 * admin moving a product into a category and narrowing its axes to match, in
 * one save, is not refused for a mismatch that was only ever true before the
 * save committed.
 *
 * Authorizes `update_product`. Locks `products`.
 * ADR-0008 · reference/write-rules/product.md
 */
final class UpdateProduct
{
    /**
     * @param  array<string, mixed>  $attributes  Product columns to change,
     *                                            plus an optional
     *                                            `attributes` key (list<int>
     *                                            of `Attribute` ids) that
     *                                            never reaches the model as
     *                                            a column.
     *
     * @throws ProductRequiresVariationException
     * @throws RemovedFromCatalogueException
     * @throws AttributeNotAllowedForCategoryException
     */
    public function handle(Product $product, array $attributes, ?User $actor): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $product);
        }

        return DB::transaction(function () use ($product, $attributes): Product {
            // Taken for the lock, not for the value. first() rather than
            // firstOrFail() so the soft-delete case is answered deliberately
            // rather than as a 404 side effect of the SoftDeletes scope.
            $live = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::product($product);
            }

            $hasVariationAxes = array_key_exists('attributes', $attributes);
            /** @var list<int> $variationAxisIds */
            $variationAxisIds = Arr::pull($attributes, 'attributes', []);

            $product->fill($attributes);

            if ($product->is_available && $product->productVariations()->count() === 0) {
                throw ProductRequiresVariationException::whenMadeAvailable($product);
            }

            $product->save();

            // Only when the form actually sent the field — a caller updating
            // just, say, is_featured via a console command should not
            // silently wipe every axis for want of the key.
            if ($hasVariationAxes) {
                $this->assertAttributesAllowedForCategory($product, $variationAxisIds);

                $product->attributes()->sync($variationAxisIds);
            }

            return $product;
        });
    }

    /**
     * @param  list<int>  $attributeIds
     *
     * @throws AttributeNotAllowedForCategoryException
     */
    private function assertAttributesAllowedForCategory(Product $product, array $attributeIds): void
    {
        if ($attributeIds === []) {
            return;
        }

        /** @var ProductCategory $category */
        $category = $product->productCategory()->firstOrFail();

        $allowed = ResolveAllowedAttributes::forCategory($category);
        $foreign = array_values(array_diff($attributeIds, $allowed));

        if ($foreign !== []) {
            throw new AttributeNotAllowedForCategoryException($product, $foreign);
        }
    }
}
