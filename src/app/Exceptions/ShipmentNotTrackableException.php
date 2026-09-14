<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Shipment;
use InvalidArgumentException;
use RuntimeException;

/**
 * A tracking sync was requested for a shipment with no tracking number yet.
 *
 * `CreateShipment` opens a shipment with `tracking_number` null — the
 * connector half that fills it in (`DispatchShipment`, not yet built) is a
 * separate slice. Until then, a shipment sitting at `Pending` has nothing a
 * courier can report on, so a sync attempt against it is a caller mistake
 * rather than a courier failure — distinct from `CourierUnavailableException`,
 * which is a real API outage on a shipment that genuinely can be tracked.
 */
class ShipmentNotTrackableException extends RuntimeException
{
    public function __construct(public readonly Shipment $shipment)
    {
        $shipmentKey = $shipment->getKey();

        if (! is_scalar($shipmentKey)) {
            throw new InvalidArgumentException('Shipment::getKey() returned a non-scalar value.');
        }

        parent::__construct(sprintf(
            'Shipment %s has no tracking number yet and cannot be synced.',
            $shipment->shipment_number ?? (string) $shipmentKey,
        ));
    }
}
