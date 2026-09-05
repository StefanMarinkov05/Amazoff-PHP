<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Http\Integrations\Speedy\Concerns\HasSpeedyCredentials;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** `POST /print` — returns a PDF body directly rather than a JSON envelope. */
class PrintLabelRequest extends Request implements HasBody
{
    use HasJsonBody;
    use HasSpeedyCredentials;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $parcelId) {}

    public function resolveEndpoint(): string
    {
        return '/print';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [...$this->credentials(), 'format' => 'PDF', 'paperSize' => 'A6', 'parcels' => [['id' => $this->parcelId]]];
    }
}
