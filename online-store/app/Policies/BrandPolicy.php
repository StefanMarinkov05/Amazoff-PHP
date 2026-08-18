<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

/**
 * Checks permissions, never role names. §3.5 requires permissions editable
 * at runtime, so `hasRole('administrator')` here would go stale the moment
 * an administrator edits a role through the panel — the permission table is
 * the only thing that reflects the current state.
 *
 * administrator is not handled here at all: Gate::before in
 * AppServiceProvider short-circuits every check for that role.
 *
 * Filament resolves this class by convention (App\Policies\BrandPolicy for
 * App\Models\Brand) and calls viewAny for both the navigation item and the
 * list page, so hiding the nav entry and blocking the route are the same
 * check rather than two that can disagree.
 */
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_brand');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->can('view_brand');
    }

    public function create(User $user): bool
    {
        return $user->can('create_brand');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can('update_brand');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->can('delete_brand');
    }
}
