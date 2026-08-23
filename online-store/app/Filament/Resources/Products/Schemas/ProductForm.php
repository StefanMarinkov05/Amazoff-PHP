<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use App\Models\ProductVariation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

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
                Select::make('attributes')
                    ->relationship('attributes', 'name')
                    ->multiple()
                    ->preload()
                    ->label('Variation axes'),
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
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR'),
                TextInput::make('discount_price')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR')
                    ->lt('regular_price'),
                DateTimePicker::make('discount_starts_at')
                    ->nullable(),
                DateTimePicker::make('discount_ends_at')
                    ->nullable(),
                TextInput::make('vat_rate')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:0,2', 'between:0,100'])
                    ->default(20.00),
                TextInput::make('min_order_quantity')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->default(1),
                // Weight and dimensions are entered in whatever unit suits
                // the product and stored canonically — grams and millimetres.
                // The *_display_unit columns remember what was typed so this
                // form shows the same number back; nothing computes from
                // them. reference/schema/open-schema-questions.md #3.
                Select::make('weight_display_unit')
                    ->label('Weight unit')
                    ->options(WeightUnit::class)
                    ->default(WeightUnit::default())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('weight_input')
                    ->label('Weight')
                    ->numeric()
                    ->step('0.001')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(false),
                Select::make('dimension_display_unit')
                    ->label('Dimension unit')
                    ->options(LengthUnit::class)
                    ->default(LengthUnit::default())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('length_input')
                    ->label('Length')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(false),
                TextInput::make('width_input')
                    ->label('Width')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(false),
                TextInput::make('height_input')
                    ->label('Height')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(false),
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
                // Create-only: §6-7 puts stock on the variation, so a product
                // saved without one has nowhere to hold a quantity and
                // CreateProduct refuses it. On edit the variations relation
                // manager owns them, and two editors for one relationship
                // disagree the moment either is used. A hidden component is
                // not dehydrated, so `variations` is simply absent from the
                // edit payload.
                Repeater::make('variations')
                    ->label('Variations')
                    ->helperText('At least one. A product with nothing to vary still needs one, because stock hangs off the variation.')
                    ->visibleOn(Operation::Create)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->maxLength(64)
                            // product_variations.sku is globally unique, so the
                            // table check spans every variation. distinct()
                            // covers two rows of this repeater sharing a SKU,
                            // which are both new and so in no table yet.
                            ->unique(table: ProductVariation::class)
                            ->distinct(),
                        TextInput::make('price')
                            ->numeric()
                            ->step('0.01')
                            ->rules(['decimal:0,2', 'max:99999999.99'])
                            ->prefix('EUR')
                            ->helperText('Leave empty to inherit the product price.')
                            ->nullable(),
                        TextInput::make('discount_price')
                            ->numeric()
                            ->step('0.01')
                            ->rules(['decimal:0,2', 'max:99999999.99'])
                            ->prefix('EUR')
                            ->lt('price')
                            ->nullable(),
                        Select::make('weight_display_unit')
                            ->label('Weight unit')
                            ->options(WeightUnit::class)
                            ->default(WeightUnit::default())
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('weight_input')
                            ->label('Weight')
                            ->numeric()
                            ->step('0.001')
                            ->minValue(0)
                            ->nullable()
                            ->dehydrated(false),
                        // Not a column. AddProductVariation turns this into an
                        // InitialStock movement against the row it creates.
                        TextInput::make('initial_quantity')
                            ->label('Opening stock')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_available')
                            ->default(true),
                    ]),
            ]);
    }
}
