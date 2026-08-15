<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Actions\Inventory\RecordInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds a variation to a product, together with the stock row it needs.
 *
 * §7 puts stock on the variation, and `inventories` has
 * `UNIQUE(product_variation_id)` — exactly one row per variation, per
 * ADR-0002. A variation without that row is not merely incomplete: every
 * inventory Action reads it with `firstOrFail()`, so the failure surfaces
 * later, at checkout, as a 500 on a product that looked sellable.
 *
 * Two tables, one invariant, so ADR-0007 requires an Action rather than the
 * relation manager's default CRUD. That is not theoretical here — the
 * variations relation manager did write Eloquent directly, and every variation
 * it created was missing its stock row.
 *
 * §20 forbids direct quantity writes, so opening stock arrives as an
 * `InitialStock` movement rather than as a quantity set behind the ledger's
 * back. A variation created with no stock gets the row at zero and no
 * movement — a ledger entry recording that nothing arrived says nothing.
 */
final class AddProductVariation
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    /**
     * @param  array<string, mixed>  $attributes  Variation columns. `product_id`
     *                                            comes from $product and is
     *                                            ignored if present.
     * @param  int  $initialQuantity  Opening stock on hand. Zero is normal:
     *                                stock usually arrives after the catalogue
     *                                entry does.
     */
    public function handle(
        Product $product,
        array $attributes,
        int $initialQuantity = 0,
        ?User $actor = null,
    ): ProductVariation {
        // Authorization first, domain validation second. An actor who may not
        // do this at all should not learn which of their arguments was wrong.
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', ProductVariation::class);
        }

        if ($initialQuantity < 0) {
            throw new \InvalidArgumentException('Initial quantity cannot be negative.');
        }

        return DB::transaction(function () use ($product, $attributes, $initialQuantity, $actor): ProductVariation {
            // Re-read rather than trusting $product->trashed(). ProductResource
            // drops the SoftDeletingScope from its route binding, so the panel
            // can open a deleted product's edit page and its relation managers
            // with it — and an in-memory model says nothing about a
            // `deleted_at` written after it was loaded.
            $live = Product::query()->whereKey($product->getKey())->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::product($product);
            }

            /** @var ProductVariation $variation */
            $variation = $product->productVariations()->create($attributes);

            // Both relations are declared without generics on their models, so
            // Larastan sees Model rather than the concrete class. Annotated
            // here rather than on the models, which are shared.
            /** @var Inventory $inventory */
            $inventory = $variation->inventory()->create([
                'current_quantity' => $initialQuantity,
            ]);

            if ($initialQuantity > 0) {
                $this->recordMovement->handle(
                    $inventory,
                    InventoryMovementType::InitialStock,
                    $initialQuantity,
                    $actor,
                );
            }

            return $variation;
        });
    }
}
