<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Role of an address snapshotted onto an order. Only `order_addresses` uses
 * this — the customer's own `addresses` table marks defaults with the
 * `is_default_billing` / `is_default_shipping` flags instead, because one
 * saved address can serve as both.
 */
enum AddressType: string implements HasLabel
{
    case Billing = 'billing';
    case Delivery = 'delivery';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Billing => 'Billing address',
            self::Delivery => 'Delivery address',
        };
    }
}
