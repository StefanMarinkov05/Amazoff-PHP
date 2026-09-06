<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Shipment\CreateShipment;
use App\Enums\OrderStatus;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * No `EditAction`: `OrderResource::getPages()` registers `index` and `view`
 * only, so the button this page used to carry pointed at a route that does
 * not exist — it rendered, and could not resolve. Orders are not editable by
 * hand; status is the one mutable field and it moves through
 * `TransitionOrderStatus`, which is what this menu calls.
 *
 * Same generated-from-the-enum shape as `OrdersTable::statusActions()`. The
 * duplication is deliberate and small: Filament header actions and table row
 * actions take a record from different places, and sharing one builder
 * between them would need a trait holding a closure over `$record` that
 * neither context supplies the same way.
 */
class ViewOrder extends ViewRecord
{
    use ReportsDomainFailures;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // §37 criterion 15: a shipment is created *from an order*, which
            // is why this lives here rather than as a create form on
            // ShipmentResource. CreateShipment enforces §28 (no shipment for
            // a cancelled, unpaid, or already-shipped order); a standalone
            // form would invite picking an order it then refuses.
            Action::make('createShipment')
                ->label('Create shipment')
                ->icon(Heroicon::OutlinedTruck)
                ->authorize(fn (): bool => auth()->user()?->can('create', Shipment::class) === true)
                ->visible(fn (Order $record): bool => ! $record->shipment()->exists())
                ->schema([
                    Select::make('carrier_id')
                        ->label('Carrier')
                        ->options(fn (): array => Carrier::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->native(false),
                ])
                ->action(fn (Order $record, array $data) => $this->reportingDomainFailures(
                    fn () => app(CreateShipment::class)->handle(
                        $record,
                        Carrier::query()->where('id', $data['carrier_id'])->firstOrFail(),
                        auth()->user(),
                    ),
                    'Shipment could not be created',
                )),
            ActionGroup::make(
                array_map(
                    fn (OrderStatus $target) => Action::make("moveTo{$target->value}")
                        ->label($target->getLabel())
                        ->color($target->getColor())
                        ->visible(fn (Order $record): bool => $record->status->canTransitionTo($target)
                            && auth()->user()?->can('updateStatus', [$record, $target]) === true)
                        ->requiresConfirmation()
                        ->modalHeading(fn (Order $record): string => "Move order {$record->serial_number} to {$target->getLabel()}?")
                        ->schema([
                            TextInput::make('reason')
                                ->label('Reason')
                                ->helperText('Recorded against the status history row (§19).')
                                ->maxLength(255)
                                ->nullable(),
                        ])
                        ->action(fn (Order $record, array $data) => app(TransitionOrderStatus::class)->handle(
                            $record,
                            $target,
                            auth()->user(),
                            $data['reason'] ?? null,
                        )),
                    OrderStatus::cases(),
                ),
            )
                ->label('Change status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->button()
                ->outlined(),
        ];
    }
}
