<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three changes, combined because they touch the same two tables and were
 * decided together:
 *
 * 1. Drops `products.weight`, `products.dimensions`, and
 *    `product_variations.weight` — the columns
 *    `2026_08_23_120000_add_structured_dimensions_and_weight` superseded and
 *    deliberately kept for one day so the replacement could be verified
 *    against a working system before removing the original.
 *    `reference/schema/open-schema-questions.md` #3 named this as the
 *    remaining step; it is done as soon as nothing reads the old columns,
 *    which grep confirms — only the factories did, and they are updated in
 *    the same commit as this migration.
 *
 * 2. Adds `product_variations.length_mm`/`width_mm`/`height_mm` — a
 *    variation's own dimensions, nullable. Null means **inherit the
 *    product's**, the same pattern `price`/`discount_price`/`weight_g`
 *    already use on this table: a variation overrides only what genuinely
 *    differs from the product. An XL shirt and an S shirt of the same
 *    product need different box dimensions; a drill in six colours does not,
 *    and stays null on every variation.
 *
 *    No new `dimension_display_unit` column on the variation — it inherits
 *    the product's display unit too. A per-variation display unit would let
 *    one variation's dimensions be typed in inches while its siblings are in
 *    cm, which is a display inconsistency serving no one; the *value* varies
 *    by variation, the unit convention does not.
 *
 * 3. Adds `product_variations.is_default`, boolean, one true per product.
 *    Not enforced by a constraint — MySQL has no partial unique index, the
 *    same reason `product_images.is_main` is not either — so it is an
 *    application invariant, owned by `SetDefaultVariation` the way
 *    `SetMainProductImage` owns `is_main`. This is the existing pattern
 *    applied to a new column, not a new one: `products.default_variation_id`
 *    was considered and rejected on the same reasoning `is_main` already
 *    settled — the flag belongs on the child it describes, not as a second
 *    door on the parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['weight', 'dimensions']);
        });

        Schema::table('product_variations', function (Blueprint $table): void {
            $table->dropColumn('weight');

            $table->unsignedInteger('length_mm')->nullable()->after('weight_display_unit');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');

            $table->boolean('is_default')->default(false)->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('dimensions', 100)->nullable();
        });

        Schema::table('product_variations', function (Blueprint $table): void {
            $table->dropColumn(['length_mm', 'width_mm', 'height_mm', 'is_default']);
            $table->decimal('weight', 8, 2)->nullable();
        });
    }
};
