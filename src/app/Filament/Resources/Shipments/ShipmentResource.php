<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments;

use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\RelationManagers\ShipmentTrackingEventsRelationManager;
use App\Filament\Resources\Shipments\Schemas\ShipmentInfolist;
use App\Filament\Resources\Shipments\Tables\ShipmentsTable;
use App\Models\Shipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * §37 criterion 15 — "a shipment can be created from an order".
 *
 * `CreateShipment` and `TransitionShipmentStatus` were built and tested with
 * no panel surface at all, the same shape `InventoryResource` was added for:
 * `warehouse_employee` holds `create_shipment`/`update_shipment` and had
 * nothing to reach them through.
 *
 * No create page and no edit page. A shipment is created *from an order*
 * (§28 refuses one for a cancelled, unpaid, or already-shipped order), so
 * creation is a row action on `OrderResource` rather than a blank form here
 * — a standalone form would invite picking an order that `CreateShipment`
 * then refuses. Status moves through `TransitionShipmentStatus`, generated
 * from `ShipmentStatus`'s matrix, the same menu shape orders and articles
 * use.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function table(Table $table): Table
    {
        return ShipmentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShipmentInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            ShipmentTrackingEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'view' => ViewShipment::route('/{record}'),
        ];
    }
}
