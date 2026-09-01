<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Accounts, and who holds which role.
 *
 * No create page: an account exists because someone registered (§7) or
 * because `UserSeeder` made it. Creating one here would need a password
 * field, which is a second credential surface to audit for no gain — an
 * administrator who needs a colleague on the panel has them register, then
 * assigns the role.
 *
 * No delete action either. `UserPolicy::delete()` already refuses
 * self-deletion, but removing any account orphans its orders, and §19
 * requires the order history to survive. Deactivation (`is_active`) is the
 * reversible path, and `canAccessPanel()` honours it on the next request —
 * `EnsureAccountIsActive` does the same for the storefront.
 *
 * Roles are assigned here, gated by `assignRole_user` rather than
 * `update_user`: granting a role is how an account gains panel access, so
 * folding it into "edit a user" would let every holder of `update_user`
 * promote themselves. See `UserPolicy`.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
