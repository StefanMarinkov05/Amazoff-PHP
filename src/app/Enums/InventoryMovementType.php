<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * §20 requires stock to change through movements rather than direct quantity
 * writes, so every one of these is an append-only ledger entry.
 */
enum InventoryMovementType: string implements HasColor, HasLabel
{
    case InitialStock = 'initial_stock';
    case NewDelivery = 'new_delivery';
    case OrderReservation = 'order_reservation';
    case CompletedSale = 'completed_sale';
    case ReservationRelease = 'reservation_release';
    case CustomerReturn = 'customer_return';
    case DamagedProduct = 'damaged_product';
    case ManualCorrection = 'manual_correction';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::InitialStock => 'Initial stock',
            self::NewDelivery => 'New delivery',
            self::OrderReservation => 'Order reservation',
            self::CompletedSale => 'Completed sale',
            self::ReservationRelease => 'Reservation release',
            self::CustomerReturn => 'Customer return',
            self::DamagedProduct => 'Damaged product',
            self::ManualCorrection => 'Manual correction',
        };
    }

    /**
     * Grouped by effect on stock rather than by type, so a movement added later
     * takes its colour from what it does to the count.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::InitialStock, self::ReservationRelease => 'gray',
            self::NewDelivery => 'success',
            self::OrderReservation, self::ManualCorrection => 'warning',
            self::CompletedSale, self::CustomerReturn => 'info',
            self::DamagedProduct => 'danger',
        };
    }
}
