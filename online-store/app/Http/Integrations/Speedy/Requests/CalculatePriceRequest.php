<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Http\Integrations\Speedy\Concerns\HasSpeedyCredentials;
use App\Support\Courier\ShipmentRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** `POST /calculate`. */
class CalculatePriceRequest extends Request implements HasBody
{
    use HasJsonBody;
    use HasSpeedyCredentials;

    protected Method $method = Method::POST;

    public function __construct(private readonly ShipmentRequest $shipment) {}

    public function resolveEndpoint(): string
    {
        return '/calculate';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            ...$this->credentials(),
            'recipient' => SpeedyShipmentPayload::recipient($this->shipment),
            'service' => ['serviceId' => 505], // standard door-to-door/office, per Speedy's published service list
            'content' => SpeedyShipmentPayload::content($this->shipment),
            'payment' => SpeedyShipmentPayload::payment($this->shipment),
        ];
    }
}
