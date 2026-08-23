<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Carrier;
use App\Models\Order;
use RuntimeException;

/**
 * A shipment was requested for an order that cannot be shipped.
 *
 * §28: a shipment cannot be created for an invalid order. "Invalid" is three
 * separate things, each with its own named constructor, because the fix
 * differs — a cancelled order is never shippable, an unpaid one becomes
 * shippable when it is paid, and an already-shipped one already has what
 * was asked for.
 *
 * Cash on delivery is the case that makes "unpaid" subtler than it looks: a
 * COD order ships *before* it is paid, by definition, so payment state alone
 * cannot decide this. `CreateShipment` reads the method, not just the status.
 */
class ShipmentNotAllowedException extends RuntimeException
{
    public function __construct(string $message, public readonly Order $order)
    {
        parent::__construct($message);
    }

    public static function orderIsCancelled(Order $order): self
    {
        return new self(sprintf(
            'Order %s is cancelled and cannot be shipped.',
            $order->serial_number,
        ), $order);
    }

    public static function orderIsUnpaid(Order $order): self
    {
        return new self(sprintf(
            'Order %s is not paid. Only cash-on-delivery orders ship before payment.',
            $order->serial_number,
        ), $order);
    }

    public static function alreadyShipped(Order $order): self
    {
        return new self(sprintf(
            'Order %s already has a shipment.',
            $order->serial_number,
        ), $order);
    }

    public static function carrierIsInactive(Order $order, Carrier $carrier): self
    {
        return new self(sprintf(
            'Carrier %s is not active and cannot take order %s.',
            $carrier->name,
            $order->serial_number,
        ), $order);
    }
}
