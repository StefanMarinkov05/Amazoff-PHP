<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `weight_unit` -> `weight_display_unit`, `dimension_unit` ->
 * `dimension_display_unit`.
 *
 * The old names claimed something false. `weight_g` holds grams **always**,
 * and `weight_unit` sitting beside it reads as "the unit this value is in" —
 * which would make `weight_g = 1600, weight_unit = kg` mean 1600 kg. It does
 * not: the value is 1600 grams, and the unit column records only that a
 * merchandiser typed 1.6 kg so the form can show `1.6` and `kg` back.
 *
 * Storage is canonical, display is remembered. The new names say so.
 *
 * Safe as a rename rather than an add-and-backfill: nothing outside the model
 * declarations reads either column yet, and no fixture writes them — they
 * were added earlier the same day and default to `cm`/`kg`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->renameColumn('dimension_unit', 'dimension_display_unit');
            $table->renameColumn('weight_unit', 'weight_display_unit');
        });

        Schema::table('product_variations', function (Blueprint $table): void {
            $table->renameColumn('weight_unit', 'weight_display_unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->renameColumn('dimension_display_unit', 'dimension_unit');
            $table->renameColumn('weight_display_unit', 'weight_unit');
        });

        Schema::table('product_variations', function (Blueprint $table): void {
            $table->renameColumn('weight_display_unit', 'weight_unit');
        });
    }
};
