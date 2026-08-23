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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds a variation together with the stock row it cannot work without.
 *
 * §7 puts stock on the variation and `inventories` has
 * `UNIQUE(product_variation_id)`. A variation without that row is not merely
 * incomplete: every inventory Action reads it with `firstOrFail()`, so the
 * failure surfaces at checkout as a 500 on a product that looked sellable.
 *
 * Opening stock arrives as an `InitialStock` movement — §20 forbids writing a
 * quantity behind the ledger's back. Zero writes no movement.
 *
 * The first variation becomes default whether or not it was asked for, the
 * same reasoning `AddProductImage` gives for promoting a product's first
 * image: a product with variations and none marked default has nothing to
 * present on a listing card, and leaving that to whoever ticks the box first
 * is how it stays unset. Composes `SetDefaultVariation` rather than writing
 * the column directly, so the demote-siblings step is never skipped.
 *
 * Authorizes `create_product_variation`. Locks nothing: adding can only move
 * §6–7's invariant in the safe direction.
 * reference/write-rules/product.md
 */
final class AddProductVariation
{
    public function __construct(
        private readonly RecordInventoryMovement $recordMovement,
        private readonly SetDefaultVariation $setDefault,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Variation columns. `product_id`
     *                                            comes from $product and is
     *                                            ignored if present.
     * @param  int  $initialQuantity  Opening stock on hand. Zero is normal:
     *                                stock usually arrives after the catalogue
     *                                entry does. Required rather than
     *                                defaulted, because `$actor` follows it
     *                                and PHP deprecates an optional parameter
     *                                declared before a required one.
     */
    public function handle(
        Product $product,
        array $attributes,
        int $initialQuantity,
        ?User $actor,
    ): ProductVariation {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', ProductVariation::class);
        }

        if ($initialQuantity < 0) {
            throw new \InvalidArgumentException('Initial quantity cannot be negative.');
        }

        return DB::transaction(function () use ($product, $attributes, $initialQuantity, $actor): ProductVariation {
            // Re-read: ProductResource drops the SoftDeletingScope from its
            // route binding, so the panel can open a deleted product's
            // relation managers.
            $live = Product::query()->whereKey($product->getKey())->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::product($product);
            }

            $isFirst = $product->productVariations()->count() === 0;
            $wantsDefault = (bool) ($attributes['is_default'] ?? false);

            // is_default is stripped rather than set: the column defaults to
            // false, and promotion goes through SetDefaultVariation so the
            // demote-siblings step is never skipped.
            $attributes = Arr::except($attributes, ['is_default']);

            /** @var ProductVariation $variation */
            $variation = $product->productVariations()->create($attributes);

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

            if ($isFirst || $wantsDefault) {
                // null, not $actor: this Action's own gate above already
                // authorized the whole write. Passing $actor through would
                // additionally demand update_product_variation — a real actor
                // holding only create_product_variation could create a
                // variation but not have it become the product's first
                // default, which is not a second discretionary act, it is a
                // structural consequence of the first one. RecordInventoryMovement
                // states the same precedent explicitly: "authorizes nothing —
                // the caller has already authorized what this records."
                $this->setDefault->handle($variation, null);
            }

            return $variation->refresh();
        });
    }
}
