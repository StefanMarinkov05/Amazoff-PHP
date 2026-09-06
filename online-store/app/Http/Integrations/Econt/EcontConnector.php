<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt;

use InvalidArgumentException;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Econt's "e-Econt Services" API — JSON-RPC-shaped POST endpoints under
 * `Nomenclatures/` and `Shipments/`, documented at ee.econt.com/services.
 * HTTP basic auth over the username/password every merchant account gets;
 * `ECONT_API_URL` in `.env` points at the public demo host by default
 * (`docs/adr/0001-tech-stack-selection.md` — "Econt has a public demo
 * environment").
 */
class EcontConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        /** @var string $url */
        $url = config('services.econt.api_url');

        return rtrim($url, '/');
    }

    protected function defaultAuth(): Authenticator
    {
        $username = config('services.econt.username');
        $password = config('services.econt.password');

        if (! is_scalar($username) || ! is_scalar($password)) {
            throw new InvalidArgumentException('Config values [services.econt.username] and [services.econt.password] must be scalar.');
        }

        return new BasicAuthenticator(
            (string) $username,
            (string) $password,
        );
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => config('couriers.timeout', 10),
        ];
    }
}
