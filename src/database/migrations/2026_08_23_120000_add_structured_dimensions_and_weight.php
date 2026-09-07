<?php

declare(strict_types=1);

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces free-text `dimensions` and decimal kilogram `weight` with
 * structured, canonical-unit columns.
 *
 * `open-schema-questions.md` #4 argued this: a courier prices on volumetric
 * weight, which needs three numbers, and parsing `"24 x 8 x 21 cm"` — plus
 * the `24x8x21cm`, `24 × 8 × 21`, `240 x 80 x 210 mm` variants a human or a
 * generator will write instead — is a bug discovered at the moment of an API
 * call rather than at data entry.
 *
 * Integers in a canonical unit, with the entry unit stored beside them:
 *
 * - `length_mm`, `width_mm`, `height_mm` — whole millimetres.
 * - `dimension_unit` — what the merchandiser typed, so the panel shows it
 *   back unchanged. Display concern only; nothing computes from it.
 * - `weight_g` — whole grams, replacing `decimal(8,2)` kilograms which could
 *   not express 5 g at all (0.01 kg is 10 g).
 * - `weight_unit` — same role as `dimension_unit`.
 *
 * ## The old columns are kept, not dropped
 *
 * `products.dimensions` and both `weight` decimals stay. Dropping a merged
 * column in the same migration that adds its replacement leaves no way to
 * verify the backfill against the original, and this migration does not
 * backfill: no production data exists yet, and the demo fixtures have not
 * been authored, which is precisely why this change is cheap *now*.
 *
 * A later migration drops them once nothing reads them —
 * `open-schema-questions.md` tracks that as the remaining step. Until then a
 * reader seeing both should treat the `_mm`/`_g` columns as authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('length_mm')->nullable()->after('weight');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');
            $table->enum('dimension_unit', LengthUnit::values())
                ->default(LengthUnit::Centimetre->value)
                ->after('height_mm');
            $table->unsignedInteger('weight_g')->nullable()->after('dimension_unit');
            $table->enum('weight_unit', WeightUnit::values())
                ->default(WeightUnit::Kilogram->value)
                ->after('weight_g');
        });

        Schema::table('product_variations', function (Blueprint $table): void {
            $table->unsignedInteger('weight_g')->nullable()->after('weight');
            $table->enum('weight_unit', WeightUnit::values())
                ->default(WeightUnit::Kilogram->value)
                ->after('weight_g');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'length_mm', 'width_mm', 'height_mm',
                'dimension_unit', 'weight_g', 'weight_unit',
            ]);
        });

        Schema::table('product_variations', function (Blueprint $table): void {
            $table->dropColumn(['weight_g', 'weight_unit']);
        });
    }
};
