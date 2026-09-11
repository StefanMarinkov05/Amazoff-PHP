<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ContactMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.email')
                    ->label('Account')
                    ->placeholder('Guest'),
                TextEntry::make('name'),
                TextEntry::make('email')
                    ->label('Email address'),
                TextEntry::make('subject')
                    ->placeholder('-'),
                TextEntry::make('message')
                    ->columnSpanFull(),
                TextEntry::make('handled_at')
                    ->label('Handled at')
                    ->dateTime()
                    ->placeholder('Not yet handled'),
                TextEntry::make('internal_note')
                    ->label('Internal note')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Received')
                    ->date(),
                TextEntry::make('updated_at')
                    ->date()
                    ->placeholder('-'),
            ]);
    }
}
