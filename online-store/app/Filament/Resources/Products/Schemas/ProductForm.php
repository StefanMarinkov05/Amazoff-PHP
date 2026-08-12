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
                    ->relationship('brand', 'name'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required(),
                TextInput::make('short_description'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('regular_price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('discount_price')
                    ->numeric()
                    ->prefix('$'),
                DateTimePicker::make('discount_starts_at'),
                DateTimePicker::make('discount_ends_at'),
                TextInput::make('vat_rate')
                    ->required()
                    ->numeric()
                    ->default(20.0),
                TextInput::make('min_order_quantity')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('weight')
                    ->numeric(),
                TextInput::make('dimensions'),
                Toggle::make('is_available')
                    ->required(),
                Toggle::make('is_featured')
                    ->required(),
                TextInput::make('seo_title'),
                TextInput::make('seo_description'),
            ]);
    }
}
