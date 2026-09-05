<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Concerns;

/**
 * Speedy authenticates each request by `userName`/`password` fields inside
 * the JSON body, not an HTTP auth header — every Speedy request merges this
 * in rather than the connector setting it once via `defaultAuth()`.
 */
trait HasSpeedyCredentials
{
    /** @return array{userName: string, password: string} */
    protected function credentials(): array
    {
        return [
            'userName' => (string) config('services.speedy.username'),
            'password' => (string) config('services.speedy.password'),
        ];
    }
}
