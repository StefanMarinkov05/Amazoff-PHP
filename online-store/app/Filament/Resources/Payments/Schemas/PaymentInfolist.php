<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Payment;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('order.serial_number')
                    ->label('Order'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('method')
                    ->badge(),
                TextEntry::make('amount')
                    ->money(),
                TextEntry::make('refunded_amount')
                    ->label('Refunded')
                    ->money(),
                TextEntry::make('remaining')
                    ->label('Refundable')
                    ->state(fn (Payment $record): string => (string) Money::of((string) $record->amount)
                        ->subtract(Money::of((string) $record->refunded_amount)))
                    ->money(),
                TextEntry::make('currency'),
                TextEntry::make('stripe_payment_intent_id')
                    ->label('Stripe PaymentIntent')
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextEntry::make('paid_at')
                    ->label('Paid at')
                    ->dateTime()
                    ->placeholder('Not paid'),
                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ]);
    }
}
