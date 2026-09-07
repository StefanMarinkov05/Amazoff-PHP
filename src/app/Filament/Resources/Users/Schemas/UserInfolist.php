<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('first_name')
                    ->label('First name'),
                TextEntry::make('last_name')
                    ->label('Last name'),
                TextEntry::make('email'),
                TextEntry::make('phone')
                    ->placeholder('—'),
                TextEntry::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('Customer'),
                TextEntry::make('is_active')
                    ->label('Active')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Deactivated')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextEntry::make('email_verified_at')
                    ->label('Email verified')
                    ->dateTime()
                    ->placeholder('Not verified'),
                TextEntry::make('created_at')
                    ->label('Registered')
                    ->dateTime(),
            ]);
    }
}
