<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the single-image pointer the variation gallery replaced.
 *
 * `product_variations.image_id` was one optional pick out of the product's own
 * images, no order, no second choice. `product_image_product_variation`
 * answers the same question with a set, and leaving both would mean a
 * resolver has to invent a precedence rule nothing enforces on write.
 *
 * `RemoveProductImage`'s in-use guard, `ProductImageInUseException`, and
 * `ProductVariationFactory`'s `image_id` all go with this column — full
 * reasoning in ADR-0013's Consequences.
 *
 * Append-only: the original column is left where it is in
 * `create_product_variations_table` and removed here, per CLAUDE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            // Drops the foreign key and the column. MySQL refuses the column
            // alone while the constraint still references it.
            $table->dropConstrainedForeignId('image_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->foreignId('image_id')->nullable()->constrained('product_images');
        });
    }
};
