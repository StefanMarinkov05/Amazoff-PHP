<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Schemas;

use App\Models\Inventory;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class InventoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('productVariation.product.name')
                    ->label('Product'),
                TextEntry::make('productVariation.sku')
                    ->label('SKU')
                    ->fontFamily('mono'),
                TextEntry::make('available')
                    ->label('Available')
                    ->state(fn (Inventory $record): int => $record->available())
                    ->badge()
                    ->color(fn (Inventory $record): string => match (true) {
                        $record->available() <= 0 => 'danger',
                        $record->available() < 5 => 'warning',
                        default => 'success',
                    }),
                TextEntry::make('current_quantity')
                    ->label('On hand')
                    ->numeric(),
                TextEntry::make('reserved_quantity')
                    ->label('Reserved')
                    ->numeric(),
                TextEntry::make('sold_quantity')
                    ->label('Sold')
                    ->numeric(),
                TextEntry::make('returned_quantity')
                    ->label('Returned')
                    ->numeric(),
                TextEntry::make('damaged_quantity')
                    ->label('Damaged')
                    ->numeric(),
                TextEntry::make('updated_at')
                    ->label('Last movement')
                    ->dateTime(),
            ]);
    }
}
