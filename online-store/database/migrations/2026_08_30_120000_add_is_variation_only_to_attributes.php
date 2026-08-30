<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an attribute as one the customer *chooses between* — Size, Colour,
 * Volume — and which therefore may only ever be a variation axis, never a
 * product-wide descriptive value.
 *
 * `SetProductAttributeValues` already refused a value whose attribute is one
 * of *that product's* axes. That check was too narrow: a product which
 * simply forgot to declare Size as an axis (or, as found live, a sneaker
 * that varies by `shoe-size` and not `size`) had nothing for the guard to
 * fire against, so "this product is both S and L" was accepted — a claim no
 * single sellable thing can make.
 *
 * Whether an attribute is a choosing one is a property of the attribute, not
 * of any one product's data, so it belongs here rather than being inferred
 * per product.
 *
 * Defaults false: a descriptive attribute is the ordinary case, and the four
 * that are genuinely axis-only are set by the vocabulary fixture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->boolean('is_variation_only')->default(false)->after('is_filterable');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn('is_variation_only');
        });
    }
};
