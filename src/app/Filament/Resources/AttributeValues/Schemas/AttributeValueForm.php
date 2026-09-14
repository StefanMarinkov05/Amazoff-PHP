<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttributeValues\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

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
                    ->required()
                    ->maxLength(100),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(120)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, Get $get) {
                            $attributeId = $get('attribute_id');

                            return $rule->where(
                                'attribute_id',
                                is_int($attributeId) || is_string($attributeId) ? $attributeId : null,
                            );
                        },
                    ),
                TextInput::make('color_hex')
                    ->maxLength(7),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
