<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContactMessageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('email')
                    ->label('Email address')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('subject')
                    ->placeholder('—')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('message')
                    ->rows(8)
                    ->columnSpanFull()
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('handled_at')
                    ->label('Handled at')
                    ->helperText('Leave empty while the message is still outstanding.')
                    ->nullable(),
                Textarea::make('internal_note')
                    ->label('Internal note')
                    ->helperText('Staff only. Never shown to the sender.')
                    ->rows(4)
                    ->columnSpanFull()
                    ->nullable(),
            ]);
    }
}
