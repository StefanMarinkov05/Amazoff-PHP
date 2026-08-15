<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductRequiresVariationException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a product with the variations and stock rows it cannot exist
 * without.
 *
 * Three tables in one operation — `products`, `product_variations`,
 * `inventories` — which is ADR-0007's threshold for an Action. The invariant
 * is §6–7's: every sellable product has at least one variation, because stock
 * hangs off the variation. Requiring it here rather than warning about it
 * later is what stops a product reaching the catalogue with nothing to sell.
 *
 * The requirement is unconditional at creation, while `UpdateProduct` only
 * enforces it for a product that is available. Nothing is gained by allowing a
 * product to be authored without a variation, and a draft product with no way
 * to hold stock is a half-entered record rather than a state worth supporting.
 *
 * `AddProductVariation` is called per variation rather than inlined, so the
 * panel's "add a variation to an existing product" path and this one create
 * identical rows. Its transaction nests into this one as a savepoint
 * (ADR-0007), so a variation failing on its unique SKU rolls back the product
 * too rather than leaving an empty one behind.
 */
final class CreateProduct
{
    public function __construct(private readonly AddProductVariation $addVariation) {}

    /**
     * @param  array<string, mixed>  $attributes  Product columns.
     * @param  list<array<string, mixed>>  $variations  One or more variations.
     *                                                  An `initial_quantity`
     *                                                  key is opening stock and
     *                                                  is not a column.
     *
     * @throws ProductRequiresVariationException
     */
    public function handle(array $attributes, array $variations, ?User $actor = null): Product
    {
        // Authorization first, domain validation second. An actor who may not
        // create products at all should not learn which of their arguments was
        // wrong, and every other Action in this namespace orders it this way.
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', Product::class);
        }

        if ($variations === []) {
            throw ProductRequiresVariationException::atCreation();
        }

        return DB::transaction(function () use ($attributes, $variations, $actor): Product {
            $product = Product::create($attributes);

            foreach ($variations as $variation) {
                // The actor is passed on rather than dropped. AddProductVariation
                // then authorizes create_product_variation as well, so a role
                // holding create_product alone cannot create variations through
                // this door that it could not create through the relation
                // manager. Passing null here would be the exact failure
                // ADR-0007 warns about: a caller silently skipping a check.
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
}
