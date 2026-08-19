<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'orderItems';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('product_name'),
                TextEntry::make('product_sku')
                    ->label('SKU'),
                TextEntry::make('variation_name')
                    ->placeholder('—'),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('unit_price')
                    ->money(),
                TextEntry::make('line_total')
                    ->money(),
                TextEntry::make('discount_amount')
                    ->money(),
                TextEntry::make('vat_rate')
                    ->suffix('%'),
                TextEntry::make('vat_amount')
                    ->money(),
                TextEntry::make('product.name')
                    ->label('Current catalogue product')
                    ->placeholder('No longer in the catalogue'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                TextColumn::make('product_name')
                    ->searchable(),
                TextColumn::make('product_sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('variation_name')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->numeric(),
                TextColumn::make('unit_price')
                    ->money(),
                TextColumn::make('line_total')
                    ->money(),
                TextColumn::make('vat_rate')
                    ->suffix('%')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat_amount')
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
