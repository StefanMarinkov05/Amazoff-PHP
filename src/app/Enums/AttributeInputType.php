<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How an attribute's values are rendered — both in the admin form and in the
 * catalogue filter panel. `Color` carries a swatch rather than a label.
 */
enum AttributeInputType: string implements HasLabel
{
    case Select = 'select';
    case Color = 'color';
    case Text = 'text';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Select => 'Dropdown',
            self::Color => 'Colour swatch',
            self::Text => 'Free text',
        };
    }
}
