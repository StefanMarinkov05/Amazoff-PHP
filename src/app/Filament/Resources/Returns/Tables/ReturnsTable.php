<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Tables;

use App\Enums\ReturnStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReturnsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // order.serial_number / order.email cross a relation — the N+1 rule.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('order')->withCount('returnItems'))
            ->columns([
                TextColumn::make('order.serial_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order.email')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('return_items_count')
                    ->label('Items')
                    ->numeric(),
                TextColumn::make('refunded_amount')
                    ->label('Refunded')
                    ->money()
                    ->placeholder('—'),
                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ReturnStatus::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
