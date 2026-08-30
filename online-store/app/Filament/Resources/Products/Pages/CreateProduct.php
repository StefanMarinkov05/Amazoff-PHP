<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalogue\CreateProduct as CreateProductAction;
use App\Actions\Catalogue\SetProductAttributeValues;
use App\Filament\Concerns\ConvertsMeasurementInput;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateProduct extends CreateRecord
{
    use ConvertsMeasurementInput;
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

        // Not a column and not CreateProduct's concern — its own Action owns
        // that set, and runs after the product exists because both its rules
        // (category allow-list, no clash with a variation axis) read state
        // the product only has once saved.
        $descriptiveValueIds = array_map(intval(...), (array) Arr::pull($data, 'descriptive_attribute_value_ids', []));

        $data = $this->convertMeasurements($data);
        $variations = array_map($this->convertMeasurements(...), $variations);

        /** @var User $actor */
        $actor = auth()->user();

        return $this->reportingDomainFailures(
            function () use ($data, $variations, $descriptiveValueIds, $actor): Model {
                $product = app(CreateProductAction::class)->handle($data, $variations, $actor);

                if ($descriptiveValueIds !== []) {
                    app(SetProductAttributeValues::class)->handle($product, $descriptiveValueIds, $actor);
                }

                return $product;
            },
            'Product could not be created',
        );
    }
}
