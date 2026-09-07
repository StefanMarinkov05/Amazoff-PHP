<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        // No DeleteAction: removing an account orphans its orders and §19
        // requires that history to survive. is_active is the reversible
        // path — see UserResource's docblock.
        return [];
    }

    /**
     * Strips `roles` unless the actor may assign them on this record.
     *
     * `UserForm` already disables the field, and a disabled Filament field is
     * not submitted — but that is the UI half. This is the enforcing half:
     * a crafted Livewire payload reaches the same mutate hook, and a hidden
     * or disabled control is not security (CLAUDE.md). Dropping the key
     * rather than throwing means an actor who may edit the user but not
     * their roles still saves their other changes.
     *
     * Two separate checks, because they fail for different reasons and one
     * of them cannot live in the policy:
     *
     * - `assignRole` is the permission half, and `UserPolicy` owns it.
     * - The self-edit refusal is here rather than in that policy because
     *   `Gate::before` short-circuits every check for an administrator —
     *   the only role holding `assignRole_user` — so a policy-level
     *   `$model->id !== $user->id` would never execute. ADR-0006 accepted
     *   that; this is the "pushes 'nobody may do X' rules out of policies"
     *   consequence `tech-stack-overview.md` describes, and an
     *   administrator clearing their own roles is exactly the edit that can
     *   lock the panel against the person making it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();
        $actor = auth()->user();

        $mayAssign = $actor?->can('assignRole', $record) === true
            && $actor->getKey() !== $record->getKey();

        if (! $mayAssign) {
            unset($data['roles']);
        }

        return $data;
    }
}
