<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn (string $state, $record): string => "{$state} {$record->last_name}")
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('Account')
                    ->placeholder('Guest')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money()
                    ->sortable(),
                TextColumn::make('subtotal_amount')
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_amount')
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat_amount')
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('invoice_required')
                    ->label('Invoice')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('anonymized_at')
                    ->label('Anonymized')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class)
                    ->multiple(),
                SelectFilter::make('payment_status')
                    ->options(PaymentStatus::class)
                    ->multiple(),
                SelectFilter::make('payment_method')
                    ->options(PaymentMethod::class),
                TernaryFilter::make('user_id')
                    ->label('Placed by')
                    ->placeholder('Everyone')
                    ->trueLabel('Registered customers')
                    ->falseLabel('Guests')
                    ->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
