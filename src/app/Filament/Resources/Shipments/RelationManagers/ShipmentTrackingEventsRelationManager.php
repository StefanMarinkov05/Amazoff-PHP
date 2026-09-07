<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only, same shape as OrderStatusHistoriesRelationManager: the courier
 * writes these through TransitionShipmentStatus, never a panel form.
 */
class ShipmentTrackingEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipmentTrackingEvents';

    protected static ?string $title = 'Tracking history';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('raw_status')
                    ->label('Courier status')
                    ->placeholder('—'),
                TextEntry::make('description')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('event_time')
                    ->label('Event time')
                    ->dateTime(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('status')
            ->defaultSort('event_time', 'desc')
            ->columns([
                TextColumn::make('event_time')
                    ->label('Event time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('raw_status')
                    ->label('Courier status')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('description')
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
