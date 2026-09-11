<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * The policy over the mechanism itself. §3.5 puts roles and permissions in
 * the administrator's hands alone, and §3.3 denies content_editor "user
 * permissions" by name.
 *
 * Note the model is spatie's Role, not one of ours — the policy is still
 * resolved by convention because the class name matches. If Role is ever
 * subclassed into App\Models, this file moves with it.
 *
 * Deleting a role does not delete the users who held it; they simply hold
 * nothing, which for staff means losing panel access at the next request.
 * That is the correct behaviour and worth knowing before using it.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_role');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('view_role');
    }

    public function create(User $user): bool
    {
        return $user->can('create_role');
    }

    /**
     * This is the permission that lets someone change what any role may do,
     * including their own. It is the most powerful ability in the catalogue,
     * and Gate::before means an administrator holds it whether or not it is
     * attached to anything.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->can('update_role');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('delete_role');
    }
}
