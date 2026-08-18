<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'id'),
                TextInput::make('serial_number')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('phone')
                    ->tel()
                    ->required(),
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name')
                    ->required(),
                Select::make('status')
                    ->options(OrderStatus::class)
                    ->required(),
                Select::make('payment_status')
                    ->options(PaymentStatus::class)
                    ->required(),
                Select::make('payment_method')
                    ->options(PaymentMethod::class)
                    ->required(),
                TextInput::make('currency')
                    ->required()
                    ->default('EUR'),
                TextInput::make('subtotal_amount')
                    ->required()
                    ->numeric(),
                TextInput::make('discount_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('shipping_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('vat_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('total_amount')
                    ->required()
                    ->numeric(),
                Textarea::make('customer_note')
                    ->columnSpanFull(),
                Textarea::make('internal_note')
                    ->columnSpanFull(),
                Toggle::make('invoice_required')
                    ->required(),
                TextInput::make('invoice_company'),
                TextInput::make('invoice_vat_number'),
                TextInput::make('invoice_eik'),
                DateTimePicker::make('anonymized_at'),
            ]);
    }
}
