<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->required()
                    ->minLength(2)
                    ->maxLength(50),
                TextInput::make('last_name')
                    ->required()
                    ->minLength(2)
                    ->maxLength(50),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(100)
                    // The users.email unique index is the binding half; this
                    // is the readable one. ignoreRecord so editing a user
                    // without changing their address is not a conflict with
                    // themselves.
                    ->unique(ignoreRecord: true),
                // ->tel() sets the input type but NOT a format rule; what
                // rejected the seeded "+1-667-538-7880" was telRegex()'s
                // default, which this schema has no reason to impose — the
                // column is a plain string(30) and the storefront never
                // validates a format either. Length is the only constraint
                // the schema actually has, so it is the only one asserted.
                TextInput::make('phone')
                    ->tel()
                    ->telRegex(null)
                    ->maxLength(30)
                    ->nullable(),
                // Deactivation rather than deletion: canAccessPanel() checks
                // is_active on every panel request and EnsureAccountIsActive
                // does the same for the storefront, so unticking this ends
                // their session on the next request either way.
                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('Inactive accounts cannot sign in, and any open session ends on their next request.')
                    ->default(true),
                /*
                 * The escalation surface. Gated by assignRole_user, not
                 * update_user, so editing a colleague's phone number and
                 * granting them the admin panel are different acts requiring
                 * different permissions — see UserPolicy.
                 *
                 * disabled() rather than hidden(): someone who may edit a
                 * user but not their roles should still see which roles that
                 * user holds. A disabled field is also not submitted, so
                 * Filament cannot write it even if the markup were tampered
                 * with — but the policy check in EditUser::mutate is what
                 * actually enforces it, since a hidden button is not
                 * security (CLAUDE.md).
                 */
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->options(fn (): array => Role::query()
                        ->whereIn('name', User::STAFF_ROLES)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    // Mirrors EditUser::mutateFormDataBeforeSave exactly,
                    // including the self-edit refusal that cannot live in
                    // UserPolicy because Gate::before short-circuits it for
                    // administrators. If these two ever disagree, the mutate
                    // hook is the one that decides.
                    ->disabled(fn (?User $record): bool => $record === null
                        || auth()->user()?->can('assignRole', $record) !== true
                        || auth()->id() === $record->getKey())
                    ->helperText('Staff roles only. A customer holds no role — that is the default authenticated state.')
                    ->columnSpanFull(),
            ]);
    }
}
