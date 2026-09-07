<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The unit a person entered a dimension in. Storage is always millimetres —
 * `products.length_mm` and its siblings are integers — and this records what
 * the merchandiser typed so the panel can show it back unchanged.
 *
 * Storing canonically and remembering the input unit, rather than storing the
 * number and the unit together, is what makes a courier's volumetric-weight
 * calculation a subtraction rather than a unit-conversion bug. It is also why
 * `open-schema-questions.md` recommended integer millimetres over a free-text
 * `"24 x 8 x 21 cm"` string: three numbers in one known unit cannot be
 * mis-parsed.
 *
 * Millimetres specifically, not centimetres, because an integer column in mm
 * expresses every dimension a parcel courier accepts without a fraction —
 * `decimal` would invite the float questions the money columns already avoid.
 */
enum LengthUnit: string implements HasLabel
{
    case Millimetre = 'mm';
    case Centimetre = 'cm';
    case Metre = 'm';
    case Inch = 'in';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function default(): self
    {
        return self::Centimetre;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Millimetre => 'mm',
            self::Centimetre => 'cm',
            self::Metre => 'm',
            self::Inch => 'in',
        };
    }

    /** How many millimetres one of this unit is. */
    public function millimetresPerUnit(): float
    {
        return match ($this) {
            self::Millimetre => 1.0,
            self::Centimetre => 10.0,
            self::Metre => 1000.0,
            self::Inch => 25.4,
        };
    }

    /** Convert a value in this unit to whole millimetres, for storage. */
    public function toMillimetres(float $value): int
    {
        return (int) round($value * $this->millimetresPerUnit());
    }

    /** Convert stored millimetres back into this unit, for display. */
    public function fromMillimetres(int $millimetres): float
    {
        return $millimetres / $this->millimetresPerUnit();
    }
}
