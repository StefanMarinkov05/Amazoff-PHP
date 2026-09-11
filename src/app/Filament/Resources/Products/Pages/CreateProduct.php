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
use InvalidArgumentException;

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
        $rawVariations = Arr::pull($data, 'variations', []);

        if (! is_array($rawVariations)) {
            throw new InvalidArgumentException('Product form [variations] must be an array.');
        }

        $variations = array_values(array_map(function (mixed $variation): array {
            if (! is_array($variation)) {
                throw new InvalidArgumentException('Product form variation must be an array.');
            }

            return $variation;
        }, $rawVariations));

        // Not a column and not CreateProduct's concern — its own Action owns
        // that set, and runs after the product exists because both its rules
        // (category allow-list, no clash with a variation axis) read state
        // the product only has once saved.
        $rawDescriptiveValueIds = Arr::pull($data, 'descriptive_attribute_value_ids', []);

        if (! is_array($rawDescriptiveValueIds)) {
            throw new InvalidArgumentException('Product form [descriptive_attribute_value_ids] must be an array.');
        }

        $descriptiveValueIds = array_values(array_map(static function (mixed $id): int {
            if (! is_scalar($id)) {
                throw new InvalidArgumentException('Product form descriptive attribute value id must be a scalar value.');
            }

            return (int) $id;
        }, $rawDescriptiveValueIds));

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
