<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductVariation;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class ProductVariationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_product_variation');
    }

    public function view(User $user, ProductVariation $productVariation): bool
    {
        return $user->can('view_product_variation');
    }

    public function create(User $user): bool
    {
        return $user->can('create_product_variation');
    }

    public function update(User $user, ProductVariation $productVariation): bool
    {
        return $user->can('update_product_variation');
    }

    public function delete(User $user, ProductVariation $productVariation): bool
    {
        return $user->can('delete_product_variation');
    }
}
