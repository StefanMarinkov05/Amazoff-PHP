<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Requests;

use App\Http\Integrations\Speedy\Concerns\HasSpeedyCredentials;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * `POST /location/office`.
 *
 * Takes the numeric site id, not a name: Speedy's `siteName` lookup only
 * matches the vendor's own Cyrillic site names, so a Latin-typed city (the
 * common case for a Bulgarian address) silently returns zero offices.
 * `SpeedyGateway::offices()` resolves the id first via `SearchSitesRequest`,
 * whose `/location/site` search does match Latin input.
 */
class SearchOfficesRequest extends Request implements HasBody
{
    use HasJsonBody;
    use HasSpeedyCredentials;

    protected Method $method = Method::POST;

    public function __construct(private readonly int $siteId) {}

    public function resolveEndpoint(): string
    {
        return '/location/office';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [...$this->credentials(), 'countryId' => 100, 'siteId' => $this->siteId];
    }
}
