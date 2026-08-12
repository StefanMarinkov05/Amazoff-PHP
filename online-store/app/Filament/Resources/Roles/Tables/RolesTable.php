<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    // administrator's zero is correct rather than missing, and
                    // a bare "0" in a permissions column reads as a bug.
                    ->formatStateUsing(fn (int $state, $record): string => $record->name === 'administrator'
                        ? 'all (via Gate::before)'
                        : (string) $state),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // No delete action, single or bulk: canAccessPanel() gates on the
            // User::STAFF_ROLES constant, so deleting a role here would strip
            // panel access from everyone holding it with no way to restore it
            // from the UI.
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['permissions', 'users']));
    }
}
