<?php

namespace App\Filament\Resources\AttributeValues\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AttributeValueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('attribute_id')
                    ->relationship('attribute', 'name')
                    ->required(),
                TextInput::make('value')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('color_hex'),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
