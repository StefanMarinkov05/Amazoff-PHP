<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Payment;
use RuntimeException;

/**
 * A refund was attempted that the payment cannot support.
 *
 * Refusals, not defects: refunding an unpaid payment, refunding more than
 * was taken, or refunding a payment that never went through Stripe and so
 * has nothing to reverse there.
 */
class RefundNotAllowedException extends RuntimeException
{
    private function __construct(public readonly Payment $payment, string $message)
    {
        parent::__construct($message);
    }

    public static function notPaid(Payment $payment): self
    {
        return new self($payment, sprintf(
            'Payment %d is %s; only a paid or partially refunded payment can be refunded.',
            $payment->getKey(),
            $payment->status->value,
        ));
    }

    public static function exceedsRemaining(Payment $payment, string $requested, string $remaining): self
    {
        return new self($payment, sprintf(
            'Cannot refund %s of payment %d: only %s remains unrefunded.',
            $requested,
            $payment->getKey(),
            $remaining,
        ));
    }

    public static function hasNoIntent(Payment $payment): self
    {
        return new self($payment, sprintf(
            'Payment %d has no Stripe PaymentIntent, so there is nothing to refund there.',
            $payment->getKey(),
        ));
    }
}
