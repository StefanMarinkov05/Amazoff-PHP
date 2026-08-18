<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Schemas;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('description')
                    ->maxLength(255)
                    ->nullable(),
                Select::make('type')
                    ->options(CouponType::class)
                    ->required()
                    ->live(),
                Select::make('scope')
                    ->options(CouponScope::class)
                    ->required()
                    ->live(),
                Select::make('products')
                    ->relationship('products', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get) => $get->enum('scope', CouponScope::class, isNullable: true) === CouponScope::Products),
                Select::make('productCategories')
                    ->relationship('productCategories', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get) => $get->enum('scope', CouponScope::class, isNullable: true) === CouponScope::Categories),
                TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->maxValue(fn (Get $get) => $get->enum('type', CouponType::class, isNullable: true) === CouponType::Percentage ? 100 : 99999999.99)
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->suffix(fn (Get $get) => $get->enum('type', CouponType::class, isNullable: true) === CouponType::Percentage ? '%' : null)
                    ->prefix(fn (Get $get) => $get->enum('type', CouponType::class, isNullable: true) === CouponType::Fixed ? 'EUR' : null),
                TextInput::make('max_discount_amount')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR')
                    ->visible(fn (Get $get) => $get->enum('type', CouponType::class, isNullable: true) === CouponType::Percentage)
                    ->nullable(),
                TextInput::make('minimum_order_value')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR')
                    ->nullable(),
                DateTimePicker::make('starts_at')
                    ->nullable(),
                DateTimePicker::make('ends_at')
                    ->after('starts_at')
                    ->nullable(),
                TextInput::make('total_usage_limit')
                    ->integer()
                    ->minValue(1)
                    ->nullable(),
                TextInput::make('usage_limit_per_customer')
                    ->integer()
                    ->minValue(1)
                    ->nullable(),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }
}
