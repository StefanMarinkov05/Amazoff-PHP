<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterSubscribers\Schemas;

use App\Enums\NewsletterStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class NewsletterSubscriberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'id'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                Select::make('status')
                    ->options(NewsletterStatus::class)
                    ->required(),
                DateTimePicker::make('subscribed_at')
                    ->required(),
            ]);
    }
}
