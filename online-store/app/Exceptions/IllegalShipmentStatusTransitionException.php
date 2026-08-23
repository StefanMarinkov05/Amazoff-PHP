<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use RuntimeException;

/**
 * A shipment status move the matrix does not allow.
 *
 * Same shape as its order and payment siblings: both ends carried as enum
 * instances so a catcher builds its own message from `getLabel()`.
 *
 * The courier half of slice 8 will map two vendor vocabularies onto
 * `ShipmentStatus` before calling the Action, so an unmappable raw status
 * fails at the mapping rather than here — this is raised when a *known*
 * status arrives out of order.
 */
class IllegalShipmentStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly ShipmentStatus $from,
        public readonly ShipmentStatus $to,
    ) {
        parent::__construct(sprintf(
            'Shipment %s cannot move from %s to %s.',
            $shipment->tracking_number ?? (string) $shipment->getKey(),
            $from->value,
            $to->value,
        ));
    }
}
