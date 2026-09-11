<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('serial_number'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Placed')
                            ->dateTime(),
                    ]),

                Section::make('Customer')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('first_name')
                            ->label('Name')
                            ->formatStateUsing(fn (string $state, $record): string => "{$state} {$record->last_name}"),
                        TextEntry::make('user.email')
                            ->label('Account')
                            ->placeholder('Guest checkout'),
                        TextEntry::make('email')
                            ->label('Email address'),
                        TextEntry::make('phone'),
                        TextEntry::make('customer_note')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Payment')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('payment_status')
                            ->badge(),
                        TextEntry::make('payment_method')
                            ->badge(),
                        TextEntry::make('currency'),
                    ]),

                Section::make('Totals')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('subtotal_amount')
                            ->label('Subtotal')
                            ->money(),
                        TextEntry::make('discount_amount')
                            ->label('Discount')
                            ->money(),
                        TextEntry::make('shipping_amount')
                            ->label('Shipping')
                            ->money(),
                        TextEntry::make('vat_amount')
                            ->label('VAT')
                            ->money(),
                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->money()
                            ->weight('bold'),
                    ]),

                Section::make('Invoice')
                    ->columns(4)
                    ->schema([
                        IconEntry::make('invoice_required')
                            ->label('Required')
                            ->boolean(),
                        TextEntry::make('invoice_company')
                            ->label('Company')
                            ->placeholder('—'),
                        TextEntry::make('invoice_vat_number')
                            ->label('VAT number')
                            ->placeholder('—'),
                        TextEntry::make('invoice_eik')
                            ->label('EIK')
                            ->placeholder('—'),
                    ]),

                Section::make('Staff')
                    ->schema([
                        // Written through the addInternalNote ability, which is
                        // separate from update_order — so it is displayed here
                        // and edited by an Action, not by a form field.
                        TextEntry::make('internal_note')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('anonymized_at')
                            ->label('Anonymized')
                            ->dateTime()
                            ->placeholder('Not anonymized'),
                    ]),
            ]);
    }
}
