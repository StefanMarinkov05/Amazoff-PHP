<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The unit a person entered a weight in. Storage is always grams —
 * `products.weight_g` and `product_variations.weight_g` are integers — and
 * this records what was typed so the panel shows it back unchanged.
 *
 * Grams rather than the previous `decimal(8,2)` kilograms for the same reason
 * dimensions moved to integer millimetres: a courier prices on a whole number
 * of grams, and an integer cannot accumulate the rounding error a decimal
 * multiplication can. `decimal(8,2)` kg also could not express 5 g without
 * rounding to 0.01 kg, which is 10 g.
 *
 * `reference/schema/open-schema-questions.md` #5 has why fixture weights are strings;
 * that reasoning is about JSON, and is unaffected by this column's type.
 */
enum WeightUnit: string implements HasLabel
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Pound = 'lb';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function default(): self
    {
        return self::Kilogram;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Gram => 'g',
            self::Kilogram => 'kg',
            self::Pound => 'lb',
        };
    }

    /** How many grams one of this unit is. */
    public function gramsPerUnit(): float
    {
        return match ($this) {
            self::Gram => 1.0,
            self::Kilogram => 1000.0,
            self::Pound => 453.59237,
        };
    }

    /** Convert a value in this unit to whole grams, for storage. */
    public function toGrams(float $value): int
    {
        return (int) round($value * $this->gramsPerUnit());
    }

    /** Convert stored grams back into this unit, for display. */
    public function fromGrams(int $grams): float
    {
        return $grams / $this->gramsPerUnit();
    }
}
