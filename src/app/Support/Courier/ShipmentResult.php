<?php

declare(strict_types=1);

namespace App\Support\Courier;

/**
 * What `createShipment()` hands back — the fields `Shipment`'s nullable
 * courier columns exist to receive (see `CreateShipment`'s class docblock).
 * `labelUrl` rather than the label bytes: both vendors return a hosted PDF
 * URL, not an inline binary, for `createLabel`/`print`.
 */
final readonly class ShipmentResult
{
    public function __construct(
        public string $shipmentNumber,
        public ?string $trackingNumber,
        public ?string $labelUrl,
        public ?string $trackingUrl,
    ) {}
}
