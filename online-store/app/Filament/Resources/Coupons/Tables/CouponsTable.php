<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Tables;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('scope')
                    ->badge(),
                TextColumn::make('value')
                    ->formatStateUsing(fn (string $state, Coupon $record): string => $record->getAttribute('type') === CouponType::Percentage
                        ? "{$state}%"
                        : Number::currency((float) $state, 'eur'))
                    ->sortable(),
                TextColumn::make('max_discount_amount')
                    ->money()
                    ->sortable(),
                TextColumn::make('minimum_order_value')
                    ->money()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total_usage_limit')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('usage_limit_per_customer')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('times_used')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(CouponType::class),
                SelectFilter::make('scope')->options(CouponScope::class),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
