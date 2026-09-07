<?php

declare(strict_types=1);

namespace App\Filament\Resources\Carriers\Pages;

use App\Filament\Resources\Carriers\CarrierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarrier extends CreateRecord
{
    protected static string $resource = CarrierResource::class;
}
