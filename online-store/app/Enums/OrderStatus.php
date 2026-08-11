<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Order lifecycle, §18 of the specification.
 *
 * Legality only, and deliberately role-blind: which employee may select which
 * status is a policy question. See ADR-0004.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case ReadyForShipment = 'ready_for_shipment';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
    case Refunded = 'refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::AwaitingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::ReadyForShipment => 'Ready for shipment',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
            self::Refunded => 'Refunded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New, self::Refunded => 'gray',
            self::AwaitingPayment, self::Returned => 'warning',
            self::Paid, self::Confirmed, self::Delivered => 'success',
            self::Preparing, self::ReadyForShipment, self::Shipped => 'info',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Statuses reachable from this one. Governed by ADR-0004 — read it before
     * widening or narrowing this table.
     *
     * `New => Confirmed` exists for cash on delivery, which skips the payment
     * leg entirely. Stripe orders go through `AwaitingPayment` first.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::AwaitingPayment, self::Confirmed, self::Cancelled],
            self::AwaitingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Confirmed, self::Cancelled, self::Refunded],
            self::Confirmed => [self::Preparing, self::Cancelled, self::Refunded],
            self::Preparing => [self::ReadyForShipment, self::Cancelled],
            self::ReadyForShipment => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered => [self::Returned],
            self::Cancelled => [self::Refunded],
            self::Returned => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
