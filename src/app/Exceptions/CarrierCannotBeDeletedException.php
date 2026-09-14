<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Carrier;
use RuntimeException;

/**
 * A carrier was refused deletion because it still has shipments.
 *
 * `shipments.carrier_id` is a `constrained()` foreign key with no cascade,
 * so the database already refuses to delete a carrier any shipment still
 * points at — as error 1451, a raw `QueryException`. This exception is
 * what turns that into a message an administrator can act on, thrown from
 * inside a lock rather than left to the database: `DeleteCarrier` re-reads
 * the live shipment count under `lockForUpdate()` before deciding, so a
 * shipment created for this carrier in the same instant cannot slip past a
 * stale count.
 *
 * `orders.carrier_id` is deliberately not checked here: it's
 * `nullOnDelete()`, not a blocking foreign key, so an order referencing
 * this carrier does not prevent the delete — the order's `carrier_id`
 * simply becomes null.
 */
class CarrierCannotBeDeletedException extends RuntimeException
{
    public function __construct(string $message, public readonly Carrier $carrier)
    {
        parent::__construct($message);
    }

    public static function hasShipments(Carrier $carrier, int $shipments): self
    {
        return new self(sprintf(
            'Carrier %s has %d shipment(s) and cannot be deleted.',
            $carrier->name,
            $shipments,
        ), $carrier);
    }
}
