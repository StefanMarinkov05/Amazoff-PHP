<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalogue\DeleteProduct;
use App\Actions\Catalogue\ForceDeleteProduct;
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
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        $data = $this->convertMeasurements($data);

        return $this->reportingDomainFailures(
            fn (): Model => app(UpdateProduct::class)->handle($record, $data, $this->actor()),
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
