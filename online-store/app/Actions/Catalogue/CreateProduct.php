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
 * Creates a product with the variations and stock rows it cannot exist without.
 *
 * Three tables in one operation, which is ADR-0007's threshold for an Action.
 * At least one variation is required unconditionally here, while
 * `UpdateProduct` enforces §6–7's invariant only for an available product —
 * see `reference/product-write-rules.md`.
 *
 * Composes `AddProductVariation` rather than inlining it, so both creation
 * paths produce identical rows. Its transaction nests as a savepoint, so a
 * variation failing on its unique SKU rolls back the product too.
 *
 * Authorizes `create_product`, and `create_product_variation` through the
 * nested Action. Locks nothing — no other request can reach a product that has
 * not committed. See `reference/product-write-rules.md`.
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
        // wrong.
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', Product::class);
        }

        if ($variations === []) {
            throw ProductRequiresVariationException::atCreation();
        }

        return DB::transaction(function () use ($attributes, $variations, $actor): Product {
            $product = Product::create($attributes);

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
}
