<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\AttributeValueNotOnProductException;
use App\Exceptions\DuplicateVariationAttributeException;
use App\Exceptions\DuplicateVariationCombinationException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets a variation's whole attribute-value combination — the answer to "what
 * makes this one different?" — in one write. `docs/explanation/
 * product-variability.md`'s first table names
 * `attribute_value_product_variation` as that answer's home.
 *
 * The contested state is the *combination as a set*, not any single
 * membership, so one Action owns the whole thing — the same reasoning
 * `SetVariationImages` gives for the gallery, and the same shape: a full
 * resync rather than attach/detach, which is what removes the "swap two
 * values" race rather than solving it.
 *
 * Two rules live here because nothing else in the schema can hold them —
 * ADR-0005 names both explicitly as outside the database's reach:
 *
 * - a value's own attribute must be one the product declared as a variation
 *   axis (`AttributeValueNotOnProductException`) — the same class of gap
 *   `ImageNotOnProductException` closes for the gallery, one table over;
 * - at most one value per attribute (`DuplicateVariationAttributeException`)
 *   — "two colours" has no meaning for a single SKU;
 * - no two variations of one product may carry the identical set
 *   (`DuplicateVariationCombinationException`) — ADR-0005's own example of
 *   "set equality across rows", named there as something no CHECK constraint
 *   can express.
 *
 * A soft-deleted sibling is excluded from the last check: it has already
 * left the catalogue (`RemovedFromCatalogueException`'s own vocabulary), and
 * refusing a genuinely new variation because a discontinued one once held the
 * same combination would be a refusal with no visible cause on the default
 * table view.
 *
 * Authorizes `update_product_variation` via `ProductVariationPolicy`. Locks
 * `products` — the same row, in the same order, as `SetVariationImages` and
 * `ForceDeleteProductVariation`, which is what keeps this from deadlocking
 * against them.
 * ADR-0005 · ADR-0008
 */
final class SetVariationAttributeValues
{
    /**
     * @param  list<int>  $attributeValueIds  An empty list clears the
     *                                        combination. Duplicates collapse
     *                                        silently.
     *
     * @throws RemovedFromCatalogueException
     * @throws AttributeValueNotOnProductException
     * @throws DuplicateVariationAttributeException
     * @throws DuplicateVariationCombinationException
     */
    public function handle(ProductVariation $variation, array $attributeValueIds, ?User $actor): ProductVariation
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $variation);
        }

        return DB::transaction(function () use ($variation, $attributeValueIds): ProductVariation {
            Product::query()
                ->whereKey($variation->product_id)
                ->lockForUpdate()
                ->first();

            // Re-read through the SoftDeletes scope rather than trusting the
            // instance the caller holds — the same reasoning SetVariationImages
            // gives for doing this before writing a pivot.
            $live = ProductVariation::query()->whereKey($variation->getKey())->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::variation($variation);
            }

            $attributeValueIds = array_values(array_unique(array_map(intval(...), $attributeValueIds)));

            $values = $this->assertValuesBelongToProduct($live, $attributeValueIds);

            $this->assertOneValuePerAttribute($live, $values);
            $this->assertCombinationIsUnique($live, $attributeValueIds);

            $live->attributeValues()->sync($attributeValueIds);

            return $live->load('attributeValues');
        });
    }

    /**
     * @param  list<int>  $attributeValueIds
     * @return Collection<int, AttributeValue>
     *
     * @throws AttributeValueNotOnProductException
     */
    private function assertValuesBelongToProduct(ProductVariation $variation, array $attributeValueIds): Collection
    {
        if ($attributeValueIds === []) {
            return collect();
        }

        $productAttributeIds = Product::query()
            ->whereKey($variation->product_id)
            ->firstOrFail()
            ->attributes()
            ->pluck('attributes.id');

        $values = AttributeValue::query()->whereIn('id', $attributeValueIds)->get();

        $foreign = $values
            ->reject(fn (AttributeValue $value): bool => $productAttributeIds->contains($value->attribute_id))
            ->pluck('id')
            ->all();

        if ($foreign !== []) {
            throw new AttributeValueNotOnProductException($variation, array_values($foreign));
        }

        return $values;
    }

    /**
     * @param  Collection<int, AttributeValue>  $values
     *
     * @throws DuplicateVariationAttributeException
     */
    private function assertOneValuePerAttribute(ProductVariation $variation, Collection $values): void
    {
        $duplicateAttributeId = $values
            ->countBy(fn (AttributeValue $value): int => $value->attribute_id)
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->first();

        if ($duplicateAttributeId !== null) {
            throw new DuplicateVariationAttributeException(
                $variation,
                Attribute::query()->findOrFail($duplicateAttributeId),
            );
        }
    }

    /**
     * @param  list<int>  $attributeValueIds
     *
     * @throws DuplicateVariationCombinationException
     */
    private function assertCombinationIsUnique(ProductVariation $variation, array $attributeValueIds): void
    {
        if ($attributeValueIds === []) {
            return;
        }

        sort($attributeValueIds);

        $conflict = ProductVariation::query()
            ->where('product_id', $variation->product_id)
            ->whereKeyNot($variation->getKey())
            ->with('attributeValues')
            ->get()
            ->first(function (ProductVariation $sibling) use ($attributeValueIds): bool {
                $siblingIds = $sibling->attributeValues->pluck('id')->sort()->values()->all();

                return $siblingIds === $attributeValueIds;
            });

        if ($conflict !== null) {
            throw new DuplicateVariationCombinationException($variation, $conflict);
        }
    }
}
