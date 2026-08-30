<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Schemas;

use App\Enums\AttributeInputType;
use App\Support\ResolveCategoryFamily;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('input_type')
                    ->options(AttributeInputType::class)
                    ->required(),
                Toggle::make('is_filterable')
                    ->required(),
                // Size, Colour, Volume: things the customer picks between,
                // so they may only ever be a variation axis. Off means the
                // attribute describes the product as a whole and can be set
                // in "Product details" on the product form.
                Toggle::make('is_variation_only')
                    ->label('Customer chooses between these')
                    ->helperText('On for Size, Colour and the like — a single product cannot be two of them at once, so they only ever go on a variation.')
                    ->required(),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
                // Empty means unrestricted — allowed for every category —
                // not "allowed nowhere". App\Support\ResolveAllowedAttributes
                // is the only place this emptiness is given that meaning; a
                // category also inherits every ancestor's allow-list, so
                // scoping "Colour" to the master "Clothing" category makes
                // it available on every leaf underneath without repeating
                // the assignment there. ->relationship() is safe here,
                // unlike ProductForm's own "Variation axes" field — this
                // resource is plain Filament CRUD with no Action
                // intercepting the save, so there is no competing write that
                // needs the pivot mid-transaction the way CreateProduct
                // does.
                Select::make('productCategories')
                    ->relationship('productCategories', 'name')
                    ->options(fn (): array => ResolveCategoryFamily::selectOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->label('Allowed categories')
                    ->helperText('Leave empty to allow this attribute for every category.'),
            ]);
    }
}
