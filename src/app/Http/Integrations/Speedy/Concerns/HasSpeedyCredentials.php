<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy\Concerns;

use InvalidArgumentException;

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
        $username = config('services.speedy.username');
        $password = config('services.speedy.password');

        if (! is_scalar($username) || ! is_scalar($password)) {
            throw new InvalidArgumentException('Config values [services.speedy.username] and [services.speedy.password] must be scalar.');
        }

        return [
            'userName' => (string) $username,
            'password' => (string) $password,
        ];
    }
}
