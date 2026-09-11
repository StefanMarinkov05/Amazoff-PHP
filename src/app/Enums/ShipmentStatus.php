<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Courier-independent shipment status. Econt and Speedy each report their own
 * vocabulary; the raw value is kept alongside in `shipments.raw_status` and
 * mapped onto these cases by the courier gateway.
 */
enum ShipmentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Shipped = 'shipped';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Shipped => 'Shipped',
            self::InTransit => 'In transit',
            self::Delivered => 'Delivered',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Governed by ADR-0004 — read it before widening or narrowing this table.
     *
     * Guards the courier mapping as much as the sequence. Econt and Speedy each
     * report their own vocabulary, and a mapping error surfaces here as a
     * regression like `Delivered => InTransit` that nothing else would catch.
     *
     * `Shipped => Delivered` skips `InTransit` because not every courier emits
     * an intermediate scan.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Shipped, self::Cancelled],
            self::Shipped => [self::InTransit, self::Delivered, self::Returned],
            self::InTransit => [self::Delivered, self::Returned],
            self::Delivered => [self::Returned],
            self::Returned, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Shipped, self::InTransit => 'info',
            self::Delivered => 'success',
            self::Returned => 'warning',
            self::Cancelled => 'danger',
        };
    }
}
