<?php

declare(strict_types=1);

namespace App\Filament\Resources\Carriers\Pages;

use App\Actions\Shipment\DeleteCarrier as DeleteCarrierAction;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Carriers\CarrierResource;
use App\Models\Carrier;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCarrier extends EditRecord
{
    use ReportsDomainFailures;

    protected static string $resource = CarrierResource::class;

    /**
     * Routed through DeleteCarrier for the same reason as ProductCategory's
     * own delete: the default action's raw $record->delete() surfaces the
     * carrier_id foreign key as an uncaught QueryException instead of a
     * message naming which dependency blocked it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var Carrier $record */
                        app(DeleteCarrierAction::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Carrier could not be deleted',
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
