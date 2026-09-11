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
 * The escalation risk here is not viewing users, it is assigning roles: that
 * is how someone becomes an administrator. Folded into update_user, anyone
 * who could edit a user's name could promote themselves. It is now its own
 * ability — assignRole_user, held only by the administrator via
 * Gate::before — and UserResource's role field is disabled without it, so
 * editing a user and granting them the panel are separate acts.
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

    /**
     * GDPR Art. 17 erasure through the admin panel (ADR-0019), for handling a
     * request emailed to the shop. `$model->id !== $user->id` for the same
     * reason `delete()` has it — an admin erasing their own account through
     * the admin tool loses panel access mid-transaction; self-service at
     * `/account/delete` is the path for that, and it does not run this
     * policy (a customer erasing themselves needs no permission).
     */
    public function erase(User $user, User $model): bool
    {
        return $user->can('erase_user') && $model->id !== $user->id;
    }

    /**
     * Granting a role is how an account gains panel access, so it is gated
     * separately from update_user rather than by it — see this class's own
     * docblock.
     *
     * Self-assignment is deliberately **not** refused here. It would read
     * naturally as `&& $model->id !== $user->id`, and that line would be
     * dead: `Gate::before` short-circuits every check for an administrator,
     * so the policy never runs for the one role that holds
     * `assignRole_user` in the first place. ADR-0006 accepted that cost and
     * `tech-stack-overview.md` names the consequence — "nobody may do X"
     * rules cannot live in a policy here. `EditUser` enforces it instead,
     * where `Gate::before` cannot reach.
     */
    public function assignRole(User $user, User $model): bool
    {
        return $user->can('assignRole_user');
    }
}
