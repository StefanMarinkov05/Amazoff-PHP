<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Tables;

use App\Models\Inventory;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // productVariation.sku and productVariation.product.name both
            // cross a relation — CLAUDE.md's N+1 rule.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('productVariation.product'))
            ->columns([
                TextColumn::make('productVariation.product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariation.sku')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->searchable(),
                // Same state/color/tooltip shape as
                // ProductVariationsRelationManager's Stock column —
                // available (current minus reserved) leads because that is
                // the number that decides whether a customer can buy;
                // the breakdown is in the tooltip.
                TextColumn::make('current_quantity')
                    ->label('Available')
                    ->badge()
                    ->state(fn (Inventory $record): string => (string) $record->available())
                    ->color(fn (Inventory $record): string => match (true) {
                        $record->available() <= 0 => 'danger',
                        $record->available() < 5 => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (Inventory $record): string => sprintf(
                        '%d on hand, %d reserved',
                        $record->current_quantity,
                        $record->reserved_quantity,
                    ))
                    ->sortable(),
                TextColumn::make('sold_quantity')
                    ->label('Sold')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('returned_quantity')
                    ->label('Returned')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('damaged_quantity')
                    ->label('Damaged')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last movement')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('current_quantity', 'asc')
            ->filters([
                Filter::make('low_stock')
                    ->label('Low stock (under 5 available)')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        '(current_quantity - reserved_quantity) < 5',
                    )),
                Filter::make('out_of_stock')
                    ->label('Out of stock')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        '(current_quantity - reserved_quantity) <= 0',
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
