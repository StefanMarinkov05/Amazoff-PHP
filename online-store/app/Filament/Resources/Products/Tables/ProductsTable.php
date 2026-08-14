<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productCategory.name')
                    ->searchable(),
                TextColumn::make('brand.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('short_description')
                    ->searchable(),
                TextColumn::make('regular_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('discount_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('discount_starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('discount_ends_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('vat_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('min_order_quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('dimensions')
                    ->searchable(),
                IconColumn::make('is_available')
                    ->boolean(),
                IconColumn::make('is_featured')
                    ->boolean(),
                TextColumn::make('seo_title')
                    ->searchable(),
                TextColumn::make('seo_description')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
