<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Pages;

use App\Actions\Catalogue\DeleteAttribute as DeleteAttributeAction;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAttribute extends EditRecord
{
    use ReportsDomainFailures;

    protected static string $resource = AttributeResource::class;

    /**
     * Routed through DeleteAttribute for the same reason as ProductCategory's
     * own delete: the default action's raw $record->delete() surfaces the
     * attribute_id foreign key as an uncaught QueryException instead of a
     * message naming which dependency blocked it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var Attribute $record */
                        app(DeleteAttributeAction::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Attribute could not be deleted',
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
