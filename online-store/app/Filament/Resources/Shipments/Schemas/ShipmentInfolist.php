<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ShipmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shipment_number')
                    ->label('Shipment number'),
                TextEntry::make('order.serial_number')
                    ->label('Order'),
                TextEntry::make('carrier.name')
                    ->label('Carrier'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('tracking_number')
                    ->label('Tracking number')
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextEntry::make('courier_tracking_url')
                    ->label('Tracking URL')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('raw_status')
                    ->label('Courier status')
                    ->placeholder('—'),
                TextEntry::make('cod_amount')
                    ->label('Cash on delivery')
                    ->money()
                    ->placeholder('— (prepaid)'),
                TextEntry::make('weight')
                    ->numeric()
                    ->suffix(' kg')
                    ->placeholder('—'),
                TextEntry::make('shipped_at')
                    ->label('Shipped at')
                    ->dateTime()
                    ->placeholder('Not yet shipped'),
                TextEntry::make('delivered_at')
                    ->label('Delivered at')
                    ->dateTime()
                    ->placeholder('Not yet delivered'),
                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ]);
    }
}
