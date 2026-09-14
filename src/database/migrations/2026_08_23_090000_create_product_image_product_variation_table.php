<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variation's own image gallery, as a many-to-many over the product's
 * existing images rather than a second image table.
 *
 * `product_images` is unchanged: it stays the asset table, product-owned,
 * `product_id` still NOT NULL. An image is uploaded once for the product and
 * shown by whichever variations it depicts, so the red photographs are one set
 * of rows regardless of how many sizes are cut from them. A mirror table per
 * variation would have re-uploaded the same file per size, and making
 * `product_id` nullable would have rewritten the merged, tested
 * AddProductImage/SetMainProductImage/RemoveProductImage triad for no gain.
 *
 * Composite primary key, no surrogate id, for the reason
 * `add_composite_keys_to_pivot_tables` already gives for the six pivots it
 * fixed: the table is always read by one side of the pair, never by an id of
 * its own, and InnoDB clusters the rows by the key.
 *
 * `position`, not `sort_order`: `product_images` already has a column of that
 * name, and the first test written against this table failed with "Column
 * 'sort_order' in field list is ambiguous" on a query joining both.
 *
 * `position` is deliberately not unique per variation — MySQL has no
 * deferrable constraints, so that guarantee would force every reorder into a
 * two-phase write to protect an ordering whose duplicates are invisible under
 * `ORDER BY position, product_image_id`. Reasoning in full: ADR-0013.
 *
 * Both keys cascade. A gallery membership is not a dependency the way
 * `product_variations.image_id` is — deleting either end means the pairing is
 * simply gone, with nothing left to repair, which is the same reasoning
 * `attribute_value_product_variation` cascades on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_product_variation', function (Blueprint $table) {
            $table->foreignId('product_image_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variation_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);

            $table->primary(['product_image_id', 'product_variation_id']);

            // The primary key covers `product_image_id` first, so a lookup by
            // variation — which is every read this table has — could not use
            // it. Reading a gallery is `WHERE product_variation_id = ? ORDER BY
            // position`, and this index serves both halves.
            $table->index(['product_variation_id', 'position'], 'pipv_variation_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_product_variation');
    }
};
