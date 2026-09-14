<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Pages;

use App\Actions\Catalogue\DeleteBrand;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Brands\BrandResource;
use App\Models\Brand;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBrand extends EditRecord
{
    use ReportsDomainFailures;

    protected static string $resource = BrandResource::class;

    /**
     * Routed through DeleteBrand for the same reason as ProductCategory's
     * own delete: the default action's raw $record->delete() surfaces the
     * brand_id foreign key as an uncaught QueryException instead of a
     * message naming which dependency blocked it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var Brand $record */
                        app(DeleteBrand::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Brand could not be deleted',
                )),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
