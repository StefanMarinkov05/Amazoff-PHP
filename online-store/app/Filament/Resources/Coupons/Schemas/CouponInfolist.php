<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Schemas;

use App\Enums\CouponType;
use App\Models\Coupon;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

/**
 * The full-text home for description/value details the table now caps —
 * ProductReviewInfolist and ContactMessageInfolist are the shape this
 * follows: TextEntry per column, no relation manager, ->columnSpanFull() on
 * anything that reads as prose.
 */
class CouponInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Coupon')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')
                            ->badge(),
                        TextEntry::make('name'),
                        TextEntry::make('description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('scope')
                            ->badge(),
                        TextEntry::make('value')
                            ->formatStateUsing(fn (string $state, Coupon $record): string => $record->getAttribute('type') === CouponType::Percentage
                                ? "{$state}%"
                                : Number::currency((float) $state, 'eur')),
                        IconEntry::make('is_active')
                            ->boolean(),
                    ]),
                Section::make('Limits')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('max_discount_amount')
                            ->money()
                            ->placeholder('No cap'),
                        TextEntry::make('minimum_order_value')
                            ->money()
                            ->placeholder('None'),
                        TextEntry::make('total_usage_limit')
                            ->numeric()
                            ->placeholder('Unlimited'),
                        TextEntry::make('usage_limit_per_customer')
                            ->numeric()
                            ->placeholder('Unlimited'),
                        TextEntry::make('redemptions_count')
                            ->label('Times used')
                            ->getStateUsing(fn (Coupon $record): int => $record->couponRedemptions()->count()),
                        TextEntry::make('starts_at')
                            ->dateTime()
                            ->placeholder('No start date'),
                        TextEntry::make('ends_at')
                            ->dateTime()
                            ->placeholder('No end date'),
                    ]),
                Section::make('Scope')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('products.name')
                            ->label('Products')
                            ->listWithLineBreaks()
                            ->placeholder('All products'),
                        TextEntry::make('productCategories.name')
                            ->label('Categories')
                            ->listWithLineBreaks()
                            ->placeholder('All categories'),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
