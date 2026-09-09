<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\RelationManagers;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReturnItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'returnItems';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('orderItem.product_name')
                    ->label('Product'),
                TextEntry::make('orderItem.product_sku')
                    ->label('SKU'),
                TextEntry::make('orderItem.variation_name')
                    ->label('Variation')
                    ->placeholder('—'),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('orderItem.unit_price')
                    ->label('Unit price')
                    ->money(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('orderItem'))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('orderItem.product_name')
                    ->label('Product'),
                TextColumn::make('orderItem.product_sku')
                    ->label('SKU'),
                TextColumn::make('orderItem.variation_name')
                    ->label('Variation')
                    ->placeholder('—'),
                TextColumn::make('quantity')
                    ->numeric(),
                TextColumn::make('orderItem.unit_price')
                    ->label('Unit price')
                    ->money(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
