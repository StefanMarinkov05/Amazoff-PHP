<?php

declare(strict_types=1);

namespace App\Support\Courier;

use App\Contracts\CourierGateway;
use App\Http\Integrations\Econt\EcontConnector;
use App\Http\Integrations\Econt\EcontGateway;
use App\Http\Integrations\Speedy\SpeedyConnector;
use App\Http\Integrations\Speedy\SpeedyGateway;
use App\Models\Carrier;
use Illuminate\Support\Manager;

/**
 * Resolves a `CourierGateway` by carrier code, decorated with
 * `CachedCourierGateway`. This is the manager the `Courier` facade proxies
 * to and the class an Action constructor-injects — CLAUDE.md's "Actions
 * inject, storefront reads through the facade" split (see
 * `docs/explanation/couriers.md`).
 *
 * `for()` rather than resolving `carriers.code` by hand at every call site —
 * it is also the reason `CarrierSeeder` insists `code` is a stable
 * identifier: renaming it silently breaks this lookup.
 */
final class CourierManager extends Manager
{
    public function getDefaultDriver(): string
    {
        /** @var string */
        return config('couriers.default', 'econt');
    }

    public function for(Carrier $carrier): CourierGateway
    {
        /** @var CourierGateway $gateway */
        $gateway = $this->driver($carrier->code);

        return $gateway;
    }

    protected function createEcontDriver(): CourierGateway
    {
        return new CachedCourierGateway(
            new EcontGateway($this->container->make(EcontConnector::class)),
        );
    }

    protected function createSpeedyDriver(): CourierGateway
    {
        return new CachedCourierGateway(
            new SpeedyGateway($this->container->make(SpeedyConnector::class)),
        );
    }
}
