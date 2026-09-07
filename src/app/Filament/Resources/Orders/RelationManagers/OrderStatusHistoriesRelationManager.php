<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderStatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'orderStatusHistories';

    protected static ?string $title = 'Status history';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('previous_status')
                    ->badge()
                    ->placeholder('—'),
                TextEntry::make('new_status')
                    ->badge(),
                TextEntry::make('user.email')
                    ->label('Changed by')
                    ->placeholder('System'),
                TextEntry::make('reason')
                    ->placeholder('—'),
                TextEntry::make('note')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Changed at')
                    ->dateTime(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('new_status')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Changed at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('previous_status')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('new_status')
                    ->badge(),
                TextColumn::make('user.email')
                    ->label('Changed by')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('reason')
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
