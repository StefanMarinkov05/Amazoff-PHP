<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use RuntimeException;

/**
 * A payment status move the matrix does not allow.
 *
 * Carries both ends as `PaymentStatus` instances rather than strings, so a
 * catcher builds its own message from `getLabel()` — the same shape
 * `IllegalOrderStatusTransitionException` uses for orders.
 *
 * ADR-0004 puts legality in the enum and enforcement in the Action; this is
 * what enforcement raises.
 */
class IllegalPaymentStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
    ) {
        parent::__construct(sprintf(
            'Payment for order #%s cannot move from %s to %s.',
            (string) $payment->order_id,
            $from->value,
            $to->value,
        ));
    }
}
