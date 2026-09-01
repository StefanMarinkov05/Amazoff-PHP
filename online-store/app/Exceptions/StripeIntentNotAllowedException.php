<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Payment;
use RuntimeException;

/**
 * A PaymentIntent was requested for a payment that must not have one.
 *
 * Two cases, both refusals rather than defects: the payment is not a Stripe
 * payment at all (a COD order skips Stripe entirely — CLAUDE.md, "Scope"),
 * or it has already reached a terminal state and creating an intent would
 * invite a second charge for money already taken.
 */
class StripeIntentNotAllowedException extends RuntimeException
{
    private function __construct(public readonly Payment $payment, string $message)
    {
        parent::__construct($message);
    }

    public static function notAStripePayment(Payment $payment): self
    {
        return new self($payment, sprintf(
            'Payment %d is a %s payment; only Stripe payments get an intent.',
            $payment->getKey(),
            $payment->method->value,
        ));
    }

    public static function alreadySettled(Payment $payment): self
    {
        return new self($payment, sprintf(
            'Payment %d is already %s; creating an intent would risk charging twice.',
            $payment->getKey(),
            $payment->status->value,
        ));
    }
}
