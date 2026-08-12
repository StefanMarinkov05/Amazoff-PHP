<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Payment lifecycle, §13 of the specification.
 *
 * The matrix guards against out-of-order Stripe webhooks rather than against a
 * user — the UNIQUE on `payment_events.stripe_event_id` stops an event being
 * processed twice, which is a different problem. See ADR-0004.
 */
enum PaymentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    /**
     * Governed by ADR-0004 — read it before widening or narrowing this table.
     *
     * `Failed => Processing` and `Failed => Paid` exist because Stripe allows a
     * failed payment attempt to be retried on the same PaymentIntent.
     *
     * `PartiallyRefunded => PartiallyRefunded` is a real move: a second partial
     * refund leaves the status alone and changes `refunded_amount`.
     *
     * `Cancelled` and `Refunded` are terminal — a cancelled PaymentIntent
     * cannot be revived, and a full refund is the end of the payment.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Paid, self::Failed, self::Cancelled],
            self::Processing => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::Refunded, self::PartiallyRefunded],
            self::Failed => [self::Pending, self::Processing, self::Paid, self::Cancelled],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Refunded => 'gray',
            self::Processing => 'info',
            self::Paid => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::PartiallyRefunded => 'warning',
        };
    }
}
