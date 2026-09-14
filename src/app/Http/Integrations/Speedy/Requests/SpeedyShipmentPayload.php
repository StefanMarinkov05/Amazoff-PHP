<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Support\Courier\ShipmentRequest;

/**
 * The `recipient`/`service`/`content`/`payment` objects `/calculate` and
 * `/shipment` both take — built once so a quote and the shipment created
 * from it can never describe the parcel differently, the same reasoning
 * `Econt\EcontLabelPayload` documents for its side.
 *
 * Field names confirmed live against Speedy's REST API 2026-09-11 (test
 * account) for `recipient`, `service`, and `content`/`payment`'s COD split —
 * see `SpeedyGateway`'s class docblock for which operations that covers.
 * `pickupOfficeId`, `addressLocation`, and `serviceIds` replaced
 * `officeId`/`address`/`serviceId`, which Speedy's API rejects outright
 * (a 200 carrying an `error` body, not an HTTP failure — `failedRequest()`
 * is what catches it).
 *
 * `serviceIds` (`[505]`, standard door-to-door/office delivery) is fixed to
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
            'addressLocation' => $shipment->officeCode === null ? array_filter([
                'countryId' => 100,
                'siteName' => $shipment->city,
                'postCode' => $shipment->postcode !== '' ? $shipment->postcode : null,
                'streetName' => $shipment->street,
            ], static fn (mixed $value): bool => $value !== null) : null,
            'pickupOfficeId' => $shipment->officeCode === null ? null : (int) $shipment->officeCode,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function service(): array
    {
        return ['serviceIds' => [505]];
    }

    /** @return array<string, mixed> */
    public static function content(ShipmentRequest $shipment): array
    {
        return [
            'parcelsCount' => 1,
            'totalWeight' => round($shipment->weightGrams / 1000, 3),
        ];
    }

    /**
     * Cash-on-delivery lives on `service.additionalServices.cod`, not
     * `payment.cod` — the latter is silently ignored rather than rejected,
     * which is what made this the harder of the two mapping mistakes to
     * notice.
     *
     * @return array<string, mixed>
     */
    public static function additionalServices(ShipmentRequest $shipment): array
    {
        return $shipment->codAmount === null ? [] : [
            'cod' => [
                'amount' => (float) $shipment->codAmount,
                'processingType' => 'CASH',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(): array
    {
        return ['courierServicePayer' => 'SENDER'];
    }
}
