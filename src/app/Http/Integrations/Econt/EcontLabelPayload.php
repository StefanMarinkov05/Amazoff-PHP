<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt;

use App\Support\Courier\ShipmentRequest;

/**
 * The `label` object `LabelService.createLabel.json` takes under both
 * `mode: "calculate"` and `mode: "create"` — built once so pricing and
 * creation can never quietly diverge in what they describe to Econt.
 *
 * Sender fields are intentionally absent: they come from the merchant
 * profile tied to the account credentials on Econt's side, not from this
 * request, for every shipment this app will ever create.
 */
final class EcontLabelPayload
{
    /** @return array<string, mixed> */
    public static function forShipment(ShipmentRequest $shipment): array
    {
        return array_filter([
            'receiverClient' => [
                'name' => $shipment->receiverName,
                'phones' => [$shipment->receiverPhone],
            ],
            'receiverAddress' => $shipment->officeCode === null ? [
                'city' => ['name' => $shipment->city, 'postCode' => $shipment->postcode],
                'street' => $shipment->street,
            ] : null,
            'receiverOfficeCode' => $shipment->officeCode,
            'shipmentType' => 'PACK',
            'weight' => round($shipment->weightGrams / 1000, 3),
            'packCount' => 1,
            'cdAmount' => $shipment->codAmount,
            'cdCurrency' => $shipment->codAmount !== null ? 'BGN' : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
