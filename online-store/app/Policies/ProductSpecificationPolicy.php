<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductSpecification;
use App\Models\User;

/**
 * Reuses ProductPolicy's permissions rather
 * than inventing product_image ones, because managing a product's images is
 * editing that product, not a separate capability anyone would grant on its
 * own. See BrandPolicy for the general pattern this deviates from.
 */
class ProductSpecificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_product');
    }

    public function create(User $user): bool
    {
        return $user->can('update_product');
    }

    public function update(User $user, ProductSpecification $productSpecification): bool
    {
        return $user->can('update_product');
    }

    public function delete(User $user, ProductSpecification $productSpecification): bool
    {
        return $user->can('update_product');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update_product');
    }
}
