<?php

declare(strict_types=1);

namespace App\Facades;

use App\Contracts\CourierGateway;
use App\Models\Carrier;
use App\Support\Courier\CourierManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static CourierGateway for(Carrier $carrier)
 * @method static CourierGateway driver(?string $driver = null)
 *
 * @see CourierManager
 */
class Courier extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CourierManager::class;
    }
}
