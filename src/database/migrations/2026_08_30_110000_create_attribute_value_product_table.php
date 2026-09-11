<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descriptive attribute values held by a product directly, rather than by a
 * variation.
 *
 * Distinct from `attribute_value_product_variation`, which answers "what
 * makes this one *different*" and forks the SKU. This one answers "what is
 * this product made of / what is in it" — a fabric composition, a set of
 * scent notes, a certification — facts true of every variation, which the
 * customer does not choose between and which must never multiply the grid.
 *
 * A pivot rather than free-text `product_specifications` because the point
 * is a controlled vocabulary: "Cotton" typed three ways is three values a
 * filter cannot group, and `attributes.is_filterable` finally has a
 * consumer. See `docs/explanation/product-variability.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_value_product', function (Blueprint $table) {
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['attribute_value_id', 'product_id']);

            // The filter reads "every product carrying value X", so the
            // non-leading column needs its own index — the composite primary
            // key above only serves lookups led by attribute_value_id.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value_product');
    }
};
