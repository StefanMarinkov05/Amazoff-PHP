<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Pages;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\RecordDamage;
use App\Enums\InventoryMovementType;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Inventory;
use App\Models\ProductVariation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * No `EditAction`: `InventoryPolicy::update()` authorizes recording a
 * movement, not editing `current_quantity` by hand — the Action enforces
 * that distinction, a policy cannot. `adjustStock` is the same action, same
 * modal shape, as `ProductVariationsRelationManager`'s row action — this
 * page is the `warehouse_employee`-reachable copy of that one. `recordDamage`
 * exists only here; the relation manager has no equivalent.
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
                ->modalHeading(fn (Inventory $record): string => "Adjust stock for {$this->variationOf($record)->sku}")
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
                            $this->variationOf($record),
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
                ->disabled(fn (Inventory $record): bool => $record->available() <= 0)
                ->tooltip(fn (Inventory $record): ?string => $record->available() <= 0
                    ? 'Nothing available to damage — all stock is either already damaged or reserved for an order.'
                    : null)
                ->modalHeading(fn (Inventory $record): string => "Record damage for {$this->variationOf($record)->sku}")
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
                        ->maxValue(fn (Inventory $record): int => $record->available())
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
                            $this->variationOf($record),
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

    /**
     * `product_variation_id` is a non-nullable, unique foreign key
     * (`create_inventories_table`) — an `Inventory` row with no variation is
     * a data-integrity violation, not a state this page should render around.
     */
    private function variationOf(Inventory $record): ProductVariation
    {
        $variation = $record->productVariation;

        if ($variation === null) {
            $key = $record->getKey();
            $key = is_scalar($key) ? $key : 'unknown';

            throw new RuntimeException("Inventory #{$key} has no product variation.");
        }

        return $variation;
    }
}
