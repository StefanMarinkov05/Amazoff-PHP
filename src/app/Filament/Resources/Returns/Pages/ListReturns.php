<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Filament\Resources\Returns\ReturnResource;
use Filament\Resources\Pages\ListRecords;

class ListReturns extends ListRecords
{
    protected static string $resource = ReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
