<?php

declare(strict_types=1);

namespace App\Filament\Resources\Carriers\Pages;

use App\Filament\Resources\Carriers\CarrierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarriers extends ListRecords
{
    protected static string $resource = CarrierResource::class;

    // The table's cod_fee column has no explicit ->label(), so it renders as
    // Filament's auto-cased "Cod fee" with no expansion of the abbreviation
    // — this is the one place on the page that spells it out.
    protected ?string $subheading = '*COD = Cash on delivery';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
