<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ReturnInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('order.serial_number')
                    ->label('Order'),
                TextEntry::make('order.email')
                    ->label('Customer'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('reason')
                    ->label('Customer reason')
                    ->columnSpanFull(),
                TextEntry::make('resolution_note')
                    ->label('Resolution note')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('refunded_amount')
                    ->label('Refunded')
                    ->money()
                    ->placeholder('Not refunded'),
                TextEntry::make('requested_at')
                    ->dateTime(),
                TextEntry::make('resolved_at')
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
