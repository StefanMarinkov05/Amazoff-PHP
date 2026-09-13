<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Order;
use App\Models\OrderReturn;
use RuntimeException;

/**
 * A return request or refund was refused (ADR-0020, CRD Arts. 9–15).
 *
 * Refusals, not defects — like `ShipmentNotAllowedException`, each has its own
 * named constructor because the fix differs: an order not yet delivered
 * becomes returnable later, an expired window never does, an already-returned
 * quantity is a stale form. A domain exception rather than a `false` return,
 * per ADR-0007, so the message reaching the customer or staff is the caller's
 * choice (`ReportsDomainFailures` in the panel, a flash on the storefront).
 */
class ReturnNotAllowedException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function orderNotDelivered(Order $order): self
    {
        return new self(sprintf(
            'Order %s has not been delivered, so nothing can be returned yet.',
            $order->serial_number,
        ));
    }

    public static function windowExpired(Order $order, int $days): self
    {
        return new self(sprintf(
            'The %d-day withdrawal period for order %s has passed.',
            $days,
            $order->serial_number,
        ));
    }

    public static function nothingSelected(Order $order): self
    {
        return new self(sprintf(
            'Select at least one item to return from order %s.',
            $order->serial_number,
        ));
    }

    public static function lineNotOnOrder(Order $order, int $orderItemId): self
    {
        return new self(sprintf(
            'Item %d is not part of order %s.',
            $orderItemId,
            $order->serial_number,
        ));
    }

    public static function quantityExceedsRemaining(Order $order, int $orderItemId, int $requested, int $remaining): self
    {
        return new self(sprintf(
            'Cannot return %d of item %d on order %s: only %d can still be returned.',
            $requested,
            $orderItemId,
            $order->serial_number,
            $remaining,
        ));
    }

    public static function notApproved(OrderReturn $return): self
    {
        return new self(sprintf(
            'Return #%d is %s; only an approved return can be refunded.',
            $return->id,
            $return->status->value,
        ));
    }
}
