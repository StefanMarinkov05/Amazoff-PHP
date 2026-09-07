<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\Tables;

use App\Enums\ShipmentStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // order.serial_number and carrier.name both cross a relation —
            // CLAUDE.md's N+1 rule.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order', 'carrier']))
            ->columns([
                TextColumn::make('shipment_number')
                    ->label('Shipment')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order.serial_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('cod_amount')
                    ->label('COD')
                    ->money()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('shipped_at')
                    ->label('Shipped')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ShipmentStatus::class)
                    ->multiple(),
                SelectFilter::make('carrier')
                    ->relationship('carrier', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
