<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Actions\Catalogue\DeleteProductCategory;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductCategory extends EditRecord
{
    use ReportsDomainFailures;

    protected static string $resource = ProductCategoryResource::class;

    /**
     * Routed through DeleteProductCategory for the same reason as Product's
     * own delete: the default action's raw $record->delete() surfaces the
     * parent_id/product_category_id foreign key as an uncaught QueryException
     * instead of a message naming which dependency blocked it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var ProductCategory $record */
                        app(DeleteProductCategory::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Category could not be deleted',
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
