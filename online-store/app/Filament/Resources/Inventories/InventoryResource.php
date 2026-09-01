<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories;

use App\Filament\Resources\Inventories\Pages\ListInventory;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Filament\Resources\Inventories\RelationManagers\InventoryMovementsRelationManager;
use App\Filament\Resources\Inventories\Schemas\InventoryInfolist;
use App\Filament\Resources\Inventories\Tables\InventoryTable;
use App\Models\Inventory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The `inventory` permission's own reachable surface.
 *
 * `warehouse_employee` holds `update_inventory` but not `viewAny_product`,
 * so `ProductVariationsRelationManager`'s "Adjust stock" action — nested
 * under `ProductResource`, gated by `ProductPolicy` — was unreachable for
 * the one role the permission catalogue grants it to. This resource is
 * `InventoryPolicy`'s own surface instead.
 *
 * No create, no edit page: `InventoryPolicy`'s own docblock states why —
 * a row exists because a variation exists, and stock changes through a
 * movement, never a direct quantity write. The index and view pages carry
 * `adjustStock`/`recordDamage` row actions (same Actions, same modal shape,
 * as `ProductVariationsRelationManager`) rather than a form that could edit
 * `current_quantity` by hand.
 */
class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Inventory';

    public static function table(Table $table): Table
    {
        return InventoryTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InventoryInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            InventoryMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventory::route('/'),
            'view' => ViewInventory::route('/{record}'),
        ];
    }
}
