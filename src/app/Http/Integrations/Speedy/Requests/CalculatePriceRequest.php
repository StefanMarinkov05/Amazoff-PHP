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
            'service' => array_filter([
                ...SpeedyShipmentPayload::service(),
                'additionalServices' => SpeedyShipmentPayload::additionalServices($this->shipment),
            ]),
            // array_filter drops 'additionalServices' when it's an empty
            // array rather than sending {} — Speedy's API rejects an empty
            // JSON array there with a 400 (it wants an object or no key at
            // all), the same array-vs-object trap `config/services.php`
            // documents for `webhook_tolerance`.
            'content' => SpeedyShipmentPayload::content($this->shipment),
            'payment' => SpeedyShipmentPayload::payment(),
        ];
    }
}
