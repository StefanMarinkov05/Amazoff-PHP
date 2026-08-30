<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContactMessageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nullable on the column and optional here: a message sent by
                // a signed-out visitor has no account, and one sent from a
                // typo'd address can be attached to the right account by
                // hand. The sender-supplied name/email above stay read-only
                // regardless — they are what was actually submitted.
                Select::make('user_id')
                    ->label('Account')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload()
                    ->placeholder('Guest — no account')
                    ->nullable(),
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
