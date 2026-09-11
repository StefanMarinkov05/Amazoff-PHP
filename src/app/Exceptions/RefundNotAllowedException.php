<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Payment;
use InvalidArgumentException;
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
            'Payment %s is %s; only a paid or partially refunded payment can be refunded.',
            self::key($payment),
            $payment->status->value,
        ));
    }

    public static function exceedsRemaining(Payment $payment, string $requested, string $remaining): self
    {
        return new self($payment, sprintf(
            'Cannot refund %s of payment %s: only %s remains unrefunded.',
            $requested,
            self::key($payment),
            $remaining,
        ));
    }

    public static function hasNoIntent(Payment $payment): self
    {
        return new self($payment, sprintf(
            'Payment %s has no Stripe PaymentIntent, so there is nothing to refund there.',
            self::key($payment),
        ));
    }

    private static function key(Payment $payment): int|string
    {
        $key = $payment->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Payment::getKey() returned neither an int nor a string.');
        }

        return $key;
    }
}
