<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Stripe = 'stripe';
    case CashOnDelivery = 'cash_on_delivery';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => 'Card payment',
            self::CashOnDelivery => 'Cash on delivery',
        };
    }

    /**
     * Cash on delivery is highlighted rather than shown neutrally: the
     * warehouse has to know before dispatch that an amount is to be collected.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Stripe => 'info',
            self::CashOnDelivery => 'warning',
        };
    }

    /**
     * Cash on delivery skips Stripe entirely: stock is reserved on order
     * confirmation and the amount is carried to the courier instead.
     */
    public function requiresOnlinePayment(): bool
    {
        return $this === self::Stripe;
    }
}
