<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;

/**
 * Turns the form's unit-relative measurement inputs into the canonical
 * columns the schema actually stores.
 *
 * `ProductForm` asks for a weight and three dimensions in whatever unit the
 * merchandiser picked, as `*_input` fields marked `dehydrated(false)` so they
 * never reach the model. This converts them: grams for weight, millimetres
 * for length, using the chosen `*_display_unit` as the conversion factor.
 *
 * The unit columns are then stored unchanged — they are a display memory, not
 * part of the value. `weight_g = 1600` with `weight_display_unit = kg` means
 * 1600 grams shown as 1.6 kg, never 1600 kg.
 *
 * Shared by `CreateProduct` and `EditProduct` rather than duplicated: the two
 * pages already both override a `handleRecord*` method, and a conversion that
 * disagreed between them would silently store different numbers for the same
 * typed input.
 */
trait ConvertsMeasurementInput
{
    /**
     * @param  array<string, mixed>  $data
     * @param  LengthUnit|null  $lengthUnitFallback  Used when `$data` has no
     *                                               `dimension_display_unit`
     *                                               of its own — a variation
     *                                               has no such column
     *                                               (`open-schema-questions.md`
     *                                               #3's "middle option": it
     *                                               shares the product's
     *                                               display unit rather than
     *                                               choosing its own). The
     *                                               caller passes the
     *                                               product's unit here for
     *                                               that case.
     * @return array<string, mixed>
     */
    protected function convertMeasurements(array $data, ?LengthUnit $lengthUnitFallback = null): array
    {
        $weightUnit = $this->resolveWeightUnit($data['weight_display_unit'] ?? null);
        $lengthUnit = array_key_exists('dimension_display_unit', $data)
            ? $this->resolveLengthUnit($data['dimension_display_unit'])
            : ($lengthUnitFallback ?? LengthUnit::default());

        $data['weight_g'] = $this->toCanonical(
            $data['weight_input'] ?? null,
            static fn (float $value): int => $weightUnit->toGrams($value),
        );

        foreach (['length', 'width', 'height'] as $axis) {
            $data["{$axis}_mm"] = $this->toCanonical(
                $data["{$axis}_input"] ?? null,
                static fn (float $value): int => $lengthUnit->toMillimetres($value),
            );
        }

        // The `*_input` keys are form-only. Filament already withholds them
        // via dehydrated(false), but a caller passing raw data would
        // otherwise hand Eloquent a non-column and trigger a mass-assignment
        // error rather than a clear one.
        unset(
            $data['weight_input'],
            $data['length_input'],
            $data['width_input'],
            $data['height_input'],
        );

        return $data;
    }

    /**
     * Null in, null out — an unspecified weight is unknown, not zero. A
     * product with no weight is legitimate (a digital item, an unmeasured
     * one) and storing 0 g would claim it is weightless.
     *
     * @param  callable(float): int  $convert
     */
    private function toCanonical(mixed $value, callable $convert): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $convert((float) $value);
    }

    private function resolveWeightUnit(mixed $value): WeightUnit
    {
        return $value instanceof WeightUnit
            ? $value
            : (WeightUnit::tryFrom((string) $value) ?? WeightUnit::default());
    }

    private function resolveLengthUnit(mixed $value): LengthUnit
    {
        return $value instanceof LengthUnit
            ? $value
            : (LengthUnit::tryFrom((string) $value) ?? LengthUnit::default());
    }
}
