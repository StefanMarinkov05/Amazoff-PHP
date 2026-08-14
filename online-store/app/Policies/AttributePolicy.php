<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attribute;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class AttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_attribute');
    }

    public function view(User $user, Attribute $attribute): bool
    {
        return $user->can('view_attribute');
    }

    public function create(User $user): bool
    {
        return $user->can('create_attribute');
    }

    public function update(User $user, Attribute $attribute): bool
    {
        return $user->can('update_attribute');
    }

    public function delete(User $user, Attribute $attribute): bool
    {
        return $user->can('delete_attribute');
    }
}
