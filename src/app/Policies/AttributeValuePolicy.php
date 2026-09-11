<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttributeValue;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class AttributeValuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_attribute_value');
    }

    public function view(User $user, AttributeValue $attributeValue): bool
    {
        return $user->can('view_attribute_value');
    }

    public function create(User $user): bool
    {
        return $user->can('create_attribute_value');
    }

    public function update(User $user, AttributeValue $attributeValue): bool
    {
        return $user->can('update_attribute_value');
    }

    public function delete(User $user, AttributeValue $attributeValue): bool
    {
        return $user->can('delete_attribute_value');
    }
}
