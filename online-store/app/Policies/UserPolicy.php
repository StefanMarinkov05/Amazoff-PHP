<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * §3.3 denies content_editor "user permissions" and "sensitive customer data"
 * by name, and this model is both. Only administrator holds these
 * permissions, and only through Gate::before rather than an attached set.
 *
 * The escalation risk here is not viewing users, it is assigning roles. That
 * happens through the Filament resource's role field, which is authorized by
 * update_user — so anyone holding update_user can grant themselves
 * administrator. Narrowing that to a separate assignRole_user ability is
 * worth doing when the User resource is built; it is not expressible until
 * there is a form to attach it to.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_user');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('view_user') || $model->id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create_user');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('update_user') || $model->id === $user->id;
    }

    /**
     * Deleting yourself locks the panel against you, and if you were the last
     * administrator it locks it against everyone. Deactivation via is_active
     * is the reversible path — canAccessPanel() checks it.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can('delete_user') && $model->id !== $user->id;
    }
}
