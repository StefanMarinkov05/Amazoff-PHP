<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\AttributeNotAllowedForCategoryException;
use App\Exceptions\ProductRequiresVariationException;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\ResolveAllowedAttributes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a product with the variations and stock rows it cannot exist without.
 *
 * 3 tables in one operation, which is ADR-0007's threshold for an Action.
 * At least 1 variation is required unconditionally here, while
 * `UpdateProduct` enforces §6–7's invariant only for an available product.
 *
 * Composes `AddProductVariation` so both creation paths produce identical
 * rows. Its transaction nests as a savepoint, so a variation failing on its
 * unique SKU rolls back the product too.
 *
 * Also attaches the product's own variation axes (`attributes`, plural — the
 * `Product` ↔ `Attribute` pivot `ProductForm` labels "Variation axes") itself
 * rather than leaving it to Filament's own post-`handleRecordCreation()`
 * `saveRelationships()` call. That timing is a real trap here and not merely
 * a style choice: a variation's own attribute-value combination is validated
 * against the product's axes *during* this transaction
 * (`SetVariationAttributeValues`, composed through `AddProductVariation`
 * below), which runs before `saveRelationships()` ever would — a perfume
 * created with Scent and Volume as its axes would have both refused with
 * `AttributeValueNotOnProductException`, against a product that, one line
 * later in the request, was going to have exactly those axes anyway.
 * Confirmed live: reverting this attach step reproduces that refusal on
 * every variation, not merely in theory.
 *
 * Also refuses a variation axis `attribute_product_category` does not allow
 * for the product's own category (or any of its ancestors) —
 * `AttributeNotAllowedForCategoryException`, the other application invariant
 * this pivot arrangement cannot express in a `CHECK` constraint. Checked
 * after the product exists, since the check needs the category it was just
 * given; the transaction still discards the whole thing on refusal.
 *
 * Authorizes `create_product`, and `create_product_variation` through the
 * nested Action. Locks nothing — no other request can reach an uncommitted
 * product.
 * ADR-0007 · reference/write-rules/product.md
 */
final class CreateProduct
{
    public function __construct(private readonly AddProductVariation $addVariation) {}

    /**
     * @param  array<string, mixed>  $attributes  Product columns, plus an
     *                                            optional `attributes` key
     *                                            (list<int> of `Attribute`
     *                                            ids — the product's own
     *                                            variation axes) that never
     *                                            reaches the model as a
     *                                            column.
     * @param  list<array<string, mixed>>  $variations  One or more variations.
     *                                                  An `initial_quantity`
     *                                                  key is opening stock and
     *                                                  is not a column.
     *
     * @throws ProductRequiresVariationException
     * @throws AttributeNotAllowedForCategoryException
     */
    public function handle(array $attributes, array $variations, ?User $actor): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', Product::class);
        }

        if ($variations === []) {
            throw ProductRequiresVariationException::atCreation();
        }

        return DB::transaction(function () use ($attributes, $variations, $actor): Product {
            /** @var list<int> $variationAxisIds */
            $variationAxisIds = Arr::pull($attributes, 'attributes', []);

            $product = Product::create($attributes);

            if ($variationAxisIds !== []) {
                $this->assertAttributesAllowedForCategory($product, $variationAxisIds);

                $product->attributes()->sync($variationAxisIds);
            }

            foreach ($variations as $variation) {
                // Actor passed on, not dropped: AddProductVariation authorizes
                // create_product_variation too. Passing null here is the
                // silent-check-skip ADR-0007 warns about.
                $this->addVariation->handle(
                    $product,
                    Arr::except($variation, ['initial_quantity']),
                    (int) ($variation['initial_quantity'] ?? 0),
                    $actor,
                );
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
        /** @var ProductCategory $category */
        $category = $product->productCategory()->firstOrFail();

        $allowed = ResolveAllowedAttributes::forCategory($category);
        $foreign = array_values(array_diff($attributeIds, $allowed));

        if ($foreign !== []) {
            throw new AttributeNotAllowedForCategoryException($product, $foreign);
        }
    }
}
