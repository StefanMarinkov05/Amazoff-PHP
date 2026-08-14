<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_category_id')
                    ->relationship('productCategory', 'name')
                    ->required(),
                Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->nullable(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(64),
                TextInput::make('short_description')
                    ->maxLength(255),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('regular_price')
                    ->required()
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:2', 'max:99999999.99'])
                    ->prefix('EUR'),
                TextInput::make('discount_price')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:2', 'max:99999999.99'])
                    ->prefix('EUR'),
                DateTimePicker::make('discount_starts_at')
                    ->nullable(),
                DateTimePicker::make('discount_ends_at')
                    ->nullable(),
                TextInput::make('vat_rate')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:2', 'between:0,100'])
                    ->default(20.00),
                TextInput::make('min_order_quantity')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('weight')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:2', 'max:999999.99'])
                    ->nullable(),
                TextInput::make('dimensions')
                    ->maxLength(100)
                    ->nullable(),
                Toggle::make('is_available')
                    ->required()
                    ->default(true),
                Toggle::make('is_featured')
                    ->required()
                    ->default(false),
                TextInput::make('seo_title')
                    ->maxLength(100)
                    ->nullable(),
                TextInput::make('seo_description')
                    ->maxLength(255)
                    ->nullable(),
            ]);
    }
}
