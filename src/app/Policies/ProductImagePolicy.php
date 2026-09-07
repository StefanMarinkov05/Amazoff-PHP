<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductImage;
use App\Models\User;

/**
 * Reuses ProductPolicy's permissions rather
 * than inventing product_image ones, because managing a product's images is
 * editing that product, not a separate capability anyone would grant on its
 * own. See BrandPolicy for the general pattern this deviates from.
 */
class ProductImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_product');
    }

    public function create(User $user): bool
    {
        return $user->can('update_product');
    }

    public function update(User $user, ProductImage $productImage): bool
    {
        return $user->can('update_product');
    }

    public function delete(User $user, ProductImage $productImage): bool
    {
        return $user->can('update_product');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update_product');
    }
}
