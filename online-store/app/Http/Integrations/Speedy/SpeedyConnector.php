<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Speedy's REST Web API — plain JSON POST endpoints under `/location`,
 * `/calculate`, `/shipment`, `/print` and `/track`, documented at
 * api.speedy.bg. Unlike Econt, Speedy takes `userName`/`password` as JSON
 * body fields on every request rather than an HTTP auth header — see
 * `Concerns\HasSpeedyCredentials`, mixed into each request instead of a
 * `defaultAuth()` here.
 */
class SpeedyConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        /** @var string $url */
        $url = config('services.speedy.api_url');

        return rtrim($url, '/');
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => config('couriers.timeout', 10),
        ];
    }
}
