<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\AttributeNotAllowedForCategoryException;
use App\Exceptions\AttributeValueIsAVariationAxisException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\ResolveAllowedAttributes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets a product's whole set of **descriptive** attribute values — "what is
 * this made of", as opposed to `SetVariationAttributeValues`' "what makes
 * this one different".
 *
 * Same shape as its variation counterpart and `SetVariationImages`: one
 * Action owns the whole set, replaced wholesale under the `products` lock,
 * which dissolves the add-while-another-removes race rather than solving it.
 *
 * Multiple values **per attribute are allowed here** and refused on a
 * variation. That asymmetry is the reason the two pivots exist at all;
 * `explanation/product-variability.md` argues it.
 *
 * Two rules, both un-constrainable in the schema (ADR-0005): the value's
 * attribute must be allowed for the product's category, and must not
 * already be one of the product's own variation axes — a product that
 * varies by Colour cannot also assert a single product-wide Colour, or a
 * filter reading both pivots returns it for a colour no variation has.
 *
 * Authorizes `update_product`. Locks `products`.
 * ADR-0005 · ADR-0008 · explanation/product-variability.md
 */
final class SetProductAttributeValues
{
    /**
     * @param  list<int>  $attributeValueIds  An empty list clears the set.
     *                                        Duplicates collapse silently.
     *
     * @throws RemovedFromCatalogueException
     * @throws AttributeNotAllowedForCategoryException
     * @throws AttributeValueIsAVariationAxisException
     */
    public function handle(Product $product, array $attributeValueIds, ?User $actor): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $product);
        }

        return DB::transaction(function () use ($product, $attributeValueIds): Product {
            $live = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::product($product);
            }

            $attributeValueIds = array_values(array_unique(array_map(intval(...), $attributeValueIds)));

            if ($attributeValueIds !== []) {
                $values = AttributeValue::query()->whereIn('id', $attributeValueIds)->get();

                $this->assertAllowedForCategory($live, $values);
                $this->assertNotAVariationAxis($live, $values);
            }

            $live->descriptiveAttributeValues()->sync($attributeValueIds);

            return $live->load('descriptiveAttributeValues');
        });
    }

    /**
     * @param  Collection<int, AttributeValue>  $values
     *
     * @throws AttributeNotAllowedForCategoryException
     */
    private function assertAllowedForCategory(Product $product, $values): void
    {
        /** @var ProductCategory $category */
        $category = $product->productCategory()->firstOrFail();

        $allowed = ResolveAllowedAttributes::forCategory($category);

        $foreign = $values
            ->reject(fn (AttributeValue $value): bool => in_array($value->attribute_id, $allowed, true))
            ->pluck('attribute_id')
            ->unique()
            ->values()
            ->all();

        if ($foreign !== []) {
            throw new AttributeNotAllowedForCategoryException($product, array_map(intval(...), $foreign));
        }
    }

    /**
     * @param  Collection<int, AttributeValue>  $values
     *
     * @throws AttributeValueIsAVariationAxisException
     */
    private function assertNotAVariationAxis(Product $product, $values): void
    {
        // Two ways an attribute can be axis-only. `is_variation_only` is a
        // property of the attribute itself — Size and Colour are choosing
        // attributes whatever any one product declares, and checking only
        // the per-product axes below let "this product is both S and L"
        // through for a product that had simply never listed Size as an
        // axis. Found live, not reasoned about.
        $axisIds = Attribute::query()
            ->where('is_variation_only', true)
            ->pluck('id')
            ->merge($product->attributes()->pluck('attributes.id'))
            ->unique();

        $clashing = $values->first(fn (AttributeValue $value): bool => $axisIds->contains($value->attribute_id));

        if ($clashing !== null) {
            /** @var Attribute $attribute */
            $attribute = $clashing->attribute()->firstOrFail();

            throw new AttributeValueIsAVariationAxisException($product, $attribute);
        }
    }
}
