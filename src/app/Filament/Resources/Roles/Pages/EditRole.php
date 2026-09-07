<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * No DeleteAction — see RoleResource for why the three roles are
     * structural rather than data.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Refuses editing a role the actor themselves holds.
     *
     * SEC-008: `update_role` is the one ability whose subject is the ability
     * system. Ordinary permissions have a blast radius of one resource;
     * this one's is the whole catalogue, because the thing it edits is the
     * thing that decides everything else. A role granted `update_role` could
     * edit *its own* permission set and grant itself every other permission
     * — confirmed by live exploitation: `content_editor` given
     * `update_role` reached `delete_user`, which §3.3 denies it by name.
     *
     * Here rather than in `RolePolicy`, for the reason `EditUser`'s docblock
     * already records: `Gate::before` short-circuits every policy check for
     * an administrator, so a policy-level `$actor->hasRole($role)` guard
     * would be dead code for the one role that can reach this page. This
     * hook runs regardless.
     *
     * §3.5 is untouched — an administrator still edits every role they do
     * not themselves hold, at runtime, without a deploy. What is refused is
     * only the reflexive case, which is the one that escalates.
     *
     * ## Why `beforeValidate` and not `mutateFormDataBeforeSave`
     *
     * The permission checkboxes are `relationship()` fields, and Filament
     * saves those inside `$this->form->getState()`
     * (`schemas/src/Concerns/HasState.php`, `saveRelationships()`) — which
     * runs *before* `mutateFormDataBeforeSave`. A `Halt` thrown from the
     * mutate hook is therefore too late: the pivot rows are already written
     * and the escalation has happened. Verified rather than reasoned: the
     * guard placed there ran (proved by throwing from it) and the permission
     * count still went 0 → 1.
     *
     * `beforeValidate` is the first hook `EditRecord::save()` calls, before
     * any state is read, so it is the only one that can stop this.
     */
    protected function beforeValidate(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();
        $actor = auth()->user();

        if ($actor instanceof User && $actor->hasRole($role->name)) {
            Notification::make()
                ->danger()
                ->title('You cannot change your own role')
                ->body("Editing {$role->name}'s permissions while holding it would let you grant yourself abilities the role is meant to exclude. Ask another administrator, or use an account that does not hold this role.")
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * The registrar caches the permission table for 24 hours. Spatie clears
     * that cache itself when a role's permissions change through the model,
     * which Filament's relationship handling does — this is belt and braces
     * for the case where a future action writes the pivot directly.
     *
     * Without it the symptom is the worst kind: the form saves, the
     * checkboxes show the new state on reload because they read the database,
     * and authorization keeps answering from the old cache. §3.5's whole
     * point is that the change takes effect without a deploy.
     */
    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
