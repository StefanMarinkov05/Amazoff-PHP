<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Support\Courier\ShipmentRequest;

/**
 * The `recipient`/`content`/`payment` objects `/calculate` and `/shipment`
 * both take — built once so a quote and the shipment created from it can
 * never describe the parcel differently, the same reasoning
 * `Econt\EcontLabelPayload` documents for its side.
 *
 * `serviceId` (505, standard door-to-door/office delivery) is fixed to
 * Speedy's published default service rather than taken from `$shipment` —
 * there is currently no path for a customer to choose a Speedy service
 * tier, only a carrier and a delivery type.
 */
final class SpeedyShipmentPayload
{
    /** @return array<string, mixed> */
    public static function recipient(ShipmentRequest $shipment): array
    {
        return array_filter([
            'privatePerson' => true,
            'phone1' => ['number' => $shipment->receiverPhone],
            'clientName' => $shipment->receiverName,
            'address' => $shipment->officeCode === null ? [
                'countryId' => 100,
                'siteName' => $shipment->city,
                'postCode' => $shipment->postcode,
                'streetName' => $shipment->street,
            ] : null,
            'officeId' => $shipment->officeCode,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function content(ShipmentRequest $shipment): array
    {
        return [
            'parcelsCount' => 1,
            'totalWeight' => round($shipment->weightGrams / 1000, 3),
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(ShipmentRequest $shipment): array
    {
        return array_filter([
            'courierServicePayer' => 'SENDER',
            'cod' => $shipment->codAmount === null ? null : [
                'amount' => (float) $shipment->codAmount,
                'processingType' => 'CASH',
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
