<?php

declare(strict_types=1);

namespace App\Filament\Resources\Carriers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CarrierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('cod_fee')
                    ->label('Cash-on-delivery fee')
                    ->numeric()
                    ->default('0.00')
                    ->prefix('€')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
