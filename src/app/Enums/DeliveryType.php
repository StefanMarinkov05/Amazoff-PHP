<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * §15 requires both delivery to a personal address and delivery to a courier
 * office, for either carrier.
 */
enum DeliveryType: string implements HasLabel
{
    case Address = 'address';
    case Office = 'office';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Address => 'To an address',
            self::Office => 'To a courier office',
        };
    }
}
