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

    /**
     * Whether the sale is a concluded contract the moment the order is
     * placed, or only once money actually arrives.
     *
     * This is what decides when the Consumer Rights Directive Art. 8(7)
     * durable-medium confirmation is sent (ADR-0022): cash on delivery
     * concludes at placement — there is no payment step that can fail, and
     * the customer has genuinely bought something — while a card sale
     * concludes at `payment_intent.succeeded`, so sending at placement told
     * customers who closed the Stripe tab that they had bought goods they
     * never paid for.
     *
     * **Deliberately not the same question as `requiresOnlinePayment()`,
     * and deliberately not spelled `=== self::Stripe` at the call sites.**
     * The two coincide today with two cases and would diverge the moment a
     * third arrives: a deposit or a digital wallet needs an online gateway
     * *and* may well conclude at placement. A negated `!== Stripe` check
     * would silently sweep such a method into "email immediately", and an
     * `=== CashOnDelivery` check would silently sweep it into "wait for
     * payment" — two spellings of the same intent that break in opposite
     * directions, which is exactly what putting the rule on the enum
     * prevents. Adding a case here forces this `match` to be answered.
     *
     * Every method sends the confirmation exactly once. What differs is
     * only *when*.
     */
    public function concludesContractAtPlacement(): bool
    {
        return match ($this) {
            self::CashOnDelivery => true,
            self::Stripe => false,
        };
    }
}
