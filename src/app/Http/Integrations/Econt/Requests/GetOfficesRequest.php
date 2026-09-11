<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** `Nomenclatures/NomenclaturesService.getOffices.json`. */
class GetOfficesRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $city,
        private readonly ?string $postcode = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/Nomenclatures/NomenclaturesService.getOffices.json';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'countryCode' => 'BGR',
            'cityName' => $this->city,
            'postCode' => $this->postcode,
            'showCargoReceptions' => false,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
