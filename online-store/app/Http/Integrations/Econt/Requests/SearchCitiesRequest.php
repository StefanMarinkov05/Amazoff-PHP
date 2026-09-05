<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * `Nomenclatures/NomenclaturesService.getCities.json` — Econt's demo API
 * returns HTTP 200 even on a malformed request, with the error described in
 * the JSON body instead. `EcontGateway::failedRequest()` is where that gets
 * turned into `CourierUnavailableException`; nothing here assumes a non-200
 * status means failure.
 */
class SearchCitiesRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $term) {}

    public function resolveEndpoint(): string
    {
        return '/Nomenclatures/NomenclaturesService.getCities.json';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'countryCode' => 'BGR',
            'name' => $this->term,
        ];
    }
}
