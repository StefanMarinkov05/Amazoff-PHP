<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.id')
                    ->label('User')
                    ->placeholder('-'),
                TextEntry::make('serial_number'),
                TextEntry::make('email')
                    ->label('Email address'),
                TextEntry::make('phone'),
                TextEntry::make('first_name'),
                TextEntry::make('last_name'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('payment_status')
                    ->badge(),
                TextEntry::make('payment_method')
                    ->badge(),
                TextEntry::make('currency'),
                TextEntry::make('subtotal_amount')
                    ->numeric(),
                TextEntry::make('discount_amount')
                    ->numeric(),
                TextEntry::make('shipping_amount')
                    ->numeric(),
                TextEntry::make('vat_amount')
                    ->numeric(),
                TextEntry::make('total_amount')
                    ->numeric(),
                TextEntry::make('customer_note')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('internal_note')
                    ->placeholder('-')
                    ->columnSpanFull(),
                IconEntry::make('invoice_required')
                    ->boolean(),
                TextEntry::make('invoice_company')
                    ->placeholder('-'),
                TextEntry::make('invoice_vat_number')
                    ->placeholder('-'),
                TextEntry::make('invoice_eik')
                    ->placeholder('-'),
                TextEntry::make('anonymized_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
