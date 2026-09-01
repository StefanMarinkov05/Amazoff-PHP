<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // order.serial_number crosses a relation — CLAUDE.md's N+1 rule.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('order'))
            ->columns([
                TextColumn::make('order.serial_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('method')
                    ->badge()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money()
                    ->sortable(),
                TextColumn::make('refunded_amount')
                    ->label('Refunded')
                    ->money()
                    ->sortable()
                    // Zero is the overwhelmingly common case and reads as
                    // noise on every row; the number matters only when it is
                    // not zero.
                    ->placeholder('—')
                    ->formatStateUsing(fn (string $state): ?string => $state === '0.00' ? null : $state),
                TextColumn::make('stripe_payment_intent_id')
                    ->label('Intent')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatus::class)
                    ->multiple(),
                SelectFilter::make('method')
                    ->options(PaymentMethod::class),
                // The reconciliation question an operator actually asks:
                // what took money and has not been fully returned?
                Filter::make('partially_refunded')
                    ->label('Has an unfinished refund')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('refunded_amount', '>', 0)
                        ->whereColumn('refunded_amount', '<', 'amount')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
