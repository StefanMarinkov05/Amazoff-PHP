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
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // payment_status is derived from the payment relation since
            // 2026-08-24 (it was a column that silently went stale). Reading
            // it per row would be one query per row, so it is eager-loaded
            // here rather than left to the accessor.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('payment'))
            ->columns([
                TextColumn::make('serial_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime()
                    ->sortable(),
                // Hidden by default, not removed: both stay searchable, so
                // looking an order up by customer name or address still
                // works from the search box without the columns occupying
                // width on every row.
                TextColumn::make('first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn (string $state, $record): string => "{$state} {$record->last_name}")
                    ->searchable(['first_name', 'last_name'])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.email')
                    ->label('Account')
                    ->placeholder('Guest')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                // Reads through the payment relation, so it cannot be
                // ->sortable(): there is no orders column to ORDER BY.
                // Sorting by it would need a join, which is not worth adding
                // until someone asks for it.
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge(),
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
                // Filters through the relation rather than an orders column.
                // An order with no payment row counts as Pending, matching
                // the accessor — otherwise "Pending" would silently exclude
                // every order that has not been paid for yet, which is the
                // majority of them.
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(PaymentStatus::class)
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];

                        if ($values === []) {
                            return $query;
                        }

                        return $query->where(function (Builder $query) use ($values): void {
                            $query->whereHas(
                                'payment',
                                fn (Builder $q): Builder => $q->whereIn('status', $values),
                            );

                            if (in_array(PaymentStatus::Pending->value, $values, true)) {
                                $query->orWhereDoesntHave('payment');
                            }
                        });
                    }),
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
