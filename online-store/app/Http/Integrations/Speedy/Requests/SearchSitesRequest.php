<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Http\Integrations\Speedy\Concerns\HasSpeedyCredentials;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** `POST /location/site` — Bulgaria's country id on Speedy's platform is 100. */
class SearchSitesRequest extends Request implements HasBody
{
    use HasJsonBody;
    use HasSpeedyCredentials;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $term) {}

    public function resolveEndpoint(): string
    {
        return '/location/site';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [...$this->credentials(), 'countryId' => 100, 'name' => $this->term];
    }
}
