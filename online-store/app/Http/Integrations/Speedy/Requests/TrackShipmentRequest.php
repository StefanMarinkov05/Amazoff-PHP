<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Http\Integrations\Speedy\Concerns\HasSpeedyCredentials;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** `POST /track`. */
class TrackShipmentRequest extends Request implements HasBody
{
    use HasJsonBody;
    use HasSpeedyCredentials;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $parcelId) {}

    public function resolveEndpoint(): string
    {
        return '/track';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [...$this->credentials(), 'parcels' => [['id' => $this->parcelId]], 'lastOperationOnly' => false];
    }
}
