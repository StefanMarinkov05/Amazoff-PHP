<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalogue\CreateProduct as CreateProductAction;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateProduct extends CreateRecord
{
    use ReportsDomainFailures;

    protected static string $resource = ProductResource::class;

    /**
     * ADR-0007: where a rule exists the resource calls the Action rather than
     * letting the page write the model. A product spans 3 tables and
     * `CreateRecord` writes one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Repeater state is keyed by item UUID; the Action takes a list.
        $variations = array_values(Arr::pull($data, 'variations', []));

        /** @var User $actor */
        $actor = auth()->user();

        return $this->reportingDomainFailures(
            fn (): Model => app(CreateProductAction::class)->handle($data, $variations, $actor),
            'Product could not be created',
        );
    }
}
