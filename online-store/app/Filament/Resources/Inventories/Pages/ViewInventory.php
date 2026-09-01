<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Pages;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\RecordDamage;
use App\Enums\InventoryMovementType;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Inventory;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * No `EditAction`: `InventoryPolicy::update()` authorizes recording a
 * movement, not editing `current_quantity` by hand — the Action enforces
 * that distinction, a policy cannot. Same two actions, same modal shape, as
 * `ProductVariationsRelationManager`'s row actions — this page is the
 * `warehouse_employee`-reachable copy of that surface, not a second design.
 */
class ViewInventory extends ViewRecord
{
    use ReportsDomainFailures;

    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjustStock')
                ->label('Adjust stock')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->modalHeading(fn (Inventory $record): string => "Adjust stock for {$record->productVariation->sku}")
                ->modalDescription(fn (Inventory $record): string => sprintf(
                    'Currently %d on hand, %d reserved, %d available.',
                    $record->current_quantity,
                    $record->reserved_quantity,
                    $record->available(),
                ))
                ->modalSubmitActionLabel('Record adjustment')
                ->schema([
                    Select::make('movement_type')
                        ->label('Reason')
                        ->options([
                            InventoryMovementType::NewDelivery->value => InventoryMovementType::NewDelivery->getLabel(),
                            InventoryMovementType::ManualCorrection->value => InventoryMovementType::ManualCorrection->getLabel(),
                        ])
                        ->default(InventoryMovementType::NewDelivery->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                    TextInput::make('delta')
                        ->label('Change')
                        ->helperText('Positive to add stock, negative to remove it. Stock already reserved for an order cannot be removed.')
                        ->integer()
                        ->required()
                        ->rules(['not_in:0']),
                    TextInput::make('reason')
                        ->label('Note')
                        ->helperText('Optional. Recorded against the movement — a delivery number, or what a stocktake found.')
                        ->maxLength(255)
                        ->nullable(),
                ])
                ->action(function (Inventory $record, array $data): void {
                    $this->reportingDomainFailures(
                        fn () => app(AdjustStock::class)->handle(
                            $record->productVariation,
                            (int) $data['delta'],
                            InventoryMovementType::from($data['movement_type']),
                            $this->actor(),
                            $data['reason'] ?? null,
                        ),
                        'Stock could not be adjusted',
                    );

                    $this->record = $record->fresh();
                }),
            Action::make('recordDamage')
                ->label('Record damage')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->modalHeading(fn (Inventory $record): string => "Record damage for {$record->productVariation->sku}")
                ->modalDescription(fn (Inventory $record): string => sprintf(
                    'Currently %d on hand, %d reserved, %d available. Damaged stock cannot come from what is already reserved for an order.',
                    $record->current_quantity,
                    $record->reserved_quantity,
                    $record->available(),
                ))
                ->modalSubmitActionLabel('Record damage')
                ->schema([
                    TextInput::make('quantity')
                        ->label('Quantity damaged')
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    TextInput::make('reason')
                        ->label('Note')
                        ->helperText('Optional. What was found, and how.')
                        ->maxLength(255)
                        ->nullable(),
                ])
                ->action(function (Inventory $record, array $data): void {
                    $this->reportingDomainFailures(
                        fn () => app(RecordDamage::class)->handle(
                            $record->productVariation,
                            (int) $data['quantity'],
                            $this->actor(),
                            $data['reason'] ?? null,
                        ),
                        'Damage could not be recorded',
                    );

                    $this->record = $record->fresh();
                }),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
