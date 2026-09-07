<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\Pages;

use App\Actions\Shipment\TransitionShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Status moves through `TransitionShipmentStatus`, generated from
 * `ShipmentStatus`'s matrix — the same menu shape `OrdersTable` and
 * `ArticlesTable` use.
 *
 * Authorization is a single `update_shipment`, unlike the order menu's
 * per-target check: `ShipmentPolicy::update()` does not route by target,
 * because ADR-0011's administrator/warehouse split is about *orders* —
 * cancelling a shipment is not the same act as cancelling the order it
 * belongs to, and §3.4 gives the warehouse employee the whole shipment
 * lifecycle.
 */
class ViewShipment extends ViewRecord
{
    use ReportsDomainFailures;

    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(
                array_map(
                    fn (ShipmentStatus $target) => Action::make("moveTo{$target->value}")
                        ->label($target->getLabel())
                        ->color($target->getColor())
                        ->authorize('update')
                        ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo($target))
                        ->requiresConfirmation()
                        ->modalHeading(fn (Shipment $record): string => "Move shipment {$record->shipment_number} to {$target->getLabel()}?")
                        ->schema([
                            TextInput::make('description')
                                ->label('Note')
                                ->helperText('Recorded against the tracking event.')
                                ->maxLength(255)
                                ->nullable(),
                        ])
                        ->action(fn (Shipment $record, array $data) => $this->reportingDomainFailures(
                            fn () => app(TransitionShipmentStatus::class)->handle(
                                $record,
                                $target,
                                auth()->user(),
                                null,
                                $data['description'] ?? null,
                            ),
                            'Shipment status could not be changed',
                        )),
                    ShipmentStatus::cases(),
                ),
            )
                ->label('Change status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->button()
                ->outlined(),
        ];
    }
}
