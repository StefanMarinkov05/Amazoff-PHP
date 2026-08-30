<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Tables;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                TextColumn::make('value')
                    ->formatStateUsing(fn (string $state, Coupon $record): string => $record->getAttribute('type') === CouponType::Percentage
                        ? "{$state}%"
                        : Number::currency((float) $state, 'eur'))
                    ->sortable(),
                // One column instead of two date columns: the pair only ever
                // means anything read together, and "1 Sep – 30 Sep" is
                // shorter than either timestamp alone was.
                TextColumn::make('starts_at')
                    ->label('Validity')
                    ->state(function (Coupon $record): string {
                        // getAttribute(), not ->starts_at: both columns are
                        // cast to datetime and are Carbon at runtime, but the
                        // model carries no property annotations for Larastan
                        // to read that from.
                        $start = $record->getAttribute('starts_at');
                        $end = $record->getAttribute('ends_at');

                        return sprintf(
                            '%s – %s',
                            $start instanceof CarbonInterface ? $start->format('j M Y') : 'always',
                            $end instanceof CarbonInterface ? $end->format('j M Y') : 'no end',
                        );
                    })
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(30)
                    // ->limit() truncates the rendered text but ->tooltip()
                    // needs the untruncated value passed back explicitly —
                    // it does not know what was cut.
                    ->tooltip(fn (Coupon $record): ?string => $record->description)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scope')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('max_discount_amount')
                    ->money()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('minimum_order_value')
                    ->money()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_usage_limit')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('usage_limit_per_customer')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('redemptions_count')
                    ->label('Times used')
                    ->counts('couponRedemptions')
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
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
