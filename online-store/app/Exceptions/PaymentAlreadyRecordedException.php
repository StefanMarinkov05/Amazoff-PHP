<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * A second payment was recorded against an order that already has one.
 *
 * `Order::payment()` is a `HasOne`, so the application treats one order as
 * carrying exactly one payment — but no unique index on `payments.order_id`
 * enforces that, which makes this an application invariant rather than a
 * constraint the database would catch. `RecordPayment` locks the order and
 * checks, so two concurrent attempts produce this rather than two rows.
 *
 * Not the same as a retried Stripe webhook, which is idempotent by
 * `payments.stripe_payment_intent_id`'s unique index. This is the case where
 * something tried to create a *different* payment for an order that is
 * already paying.
 */
class PaymentAlreadyRecordedException extends RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct(sprintf(
            'Order %s already has a payment recorded against it.',
            $order->serial_number,
        ));
    }
}
