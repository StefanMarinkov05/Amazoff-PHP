<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** `Shipments/ShipmentService.getShipmentStatuses.json`. */
class GetShipmentStatusesRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $shipmentNumber) {}

    public function resolveEndpoint(): string
    {
        return '/Shipments/ShipmentService.getShipmentStatuses.json';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'shipmentNumbers' => [$this->shipmentNumber],
        ];
    }
}
