<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

/**
 * §3.5: roles and permissions editable without a deploy. This is the surface
 * that makes that true — until it existed, "runtime-editable" was an
 * architectural property nobody could exercise, since changing a role meant
 * editing RoleSeeder and re-seeding.
 *
 * No create and no delete page. The three roles are structural: `canAccessPanel()`
 * gates on `User::STAFF_ROLES`, which is a PHP constant, so a role created here
 * would grant no panel access until someone edited that constant and deployed.
 * A UI that appears to work while doing nothing is worse than its absence.
 * ADR-0006 records the decision; RolePolicy still carries create and delete
 * because the permissions exist and a later UI may use them.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Roles & permissions';

    /**
     * Filament derives this from the model class otherwise, which for a
     * package model produces "Spatie\Permission\Models\Role".
     */
    protected static ?string $modelLabel = 'role';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
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
            'index' => ListRoles::route('/'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
