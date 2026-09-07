<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_tag');
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->can('view_tag');
    }

    public function create(User $user): bool
    {
        return $user->can('create_tag');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->can('update_tag');
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->can('delete_tag');
    }
}
