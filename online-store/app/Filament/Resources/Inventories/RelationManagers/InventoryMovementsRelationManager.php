<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only, same shape as OrderStatusHistoriesRelationManager: §20's ledger
 * is what AdjustStock/RecordDamage/every other inventory Action writes to,
 * never edited directly, so there is no create/edit page here either.
 */
class InventoryMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryMovements';

    protected static ?string $title = 'Movement history';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('movement_type')
                    ->badge(),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('createdBy.email')
                    ->label('Recorded by')
                    ->placeholder('System'),
                TextEntry::make('note')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Recorded at')
                    ->dateTime(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('movement_type')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('movement_type')
                    ->badge(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('createdBy.email')
                    ->label('Recorded by')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('note')
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
