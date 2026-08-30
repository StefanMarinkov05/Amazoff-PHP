<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalogue\DeleteProduct;
use App\Actions\Catalogue\ForceDeleteProduct;
use App\Actions\Catalogue\SetProductAttributeValues;
use App\Actions\Catalogue\UpdateProduct;
use App\Filament\Concerns\ConvertsMeasurementInput;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditProduct extends EditRecord
{
    use ConvertsMeasurementInput;
    use ReportsDomainFailures;

    protected static string $resource = ProductResource::class;

    /**
     * Delete and force-delete are routed through their Actions for the same
     * reason as the save: both span more than the `products` row. The default
     * force-delete raises error 1451 for any product that has a variation.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var Product $record */
                        app(DeleteProduct::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Product could not be deleted',
                )),
            ForceDeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var Product $record */
                        app(ForceDeleteProduct::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Product could not be permanently deleted',
                )),
            RestoreAction::make(),
        ];
    }

    /**
     * Two things the form cannot fill itself.
     *
     * `attributes` (the product's own variation axes) isn't a column —
     * `ProductForm`'s field is a plain `->options()` Select, not
     * `->relationship()` (see that file for why), so nothing hydrates it
     * automatically the way a `->relationship()` field would on open.
     *
     * The `*_input` measurement fields aren't columns either, and their
     * absence here was a silent data-loss bug rather than a cosmetic gap:
     * they loaded blank, and `convertMeasurements()` on save read blank as
     * "unspecified" and wrote `null`, so renaming a product erased its
     * weight and all three dimensions. See the trait's own docblock.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $record */
        $record = $this->getRecord();

        $data['attributes'] = $record->attributes()->pluck('attributes.id')->all();
        $data['descriptive_attribute_value_ids'] = $record->descriptiveAttributeValues()
            ->pluck('attribute_values.id')
            ->all();

        return $this->hydrateMeasurementInput($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        // Not a column, and its own Action's business — see CreateProduct's
        // matching note. Pulled before convertMeasurements so it never
        // reaches UpdateProduct as a stray key.
        $hasDescriptiveValues = array_key_exists('descriptive_attribute_value_ids', $data);
        $descriptiveValueIds = array_map(intval(...), (array) Arr::pull($data, 'descriptive_attribute_value_ids', []));

        $data = $this->convertMeasurements($data);

        return $this->reportingDomainFailures(
            function () use ($record, $data, $hasDescriptiveValues, $descriptiveValueIds): Model {
                $product = app(UpdateProduct::class)->handle($record, $data, $this->actor());

                // Only when the form sent the field, so clearing it is a
                // deliberate act rather than a side effect of a partial
                // payload — the same discipline UpdateProduct keeps for the
                // variation axes beside it.
                if ($hasDescriptiveValues) {
                    app(SetProductAttributeValues::class)->handle($product, $descriptiveValueIds, $this->actor());
                }

                return $product;
            },
            'Product could not be saved',
        );
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
