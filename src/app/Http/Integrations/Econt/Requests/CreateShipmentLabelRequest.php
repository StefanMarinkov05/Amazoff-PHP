<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt\Requests;

use App\Http\Integrations\Econt\EcontLabelPayload;
use App\Support\Courier\ShipmentRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** Same endpoint as `CalculateShipmentRequest`, `mode: "create"`. */
class CreateShipmentLabelRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly ShipmentRequest $shipment) {}

    public function resolveEndpoint(): string
    {
        return '/Shipments/LabelService.createLabel.json';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'mode' => 'create',
            'label' => EcontLabelPayload::forShipment($this->shipment),
        ];
    }
}
