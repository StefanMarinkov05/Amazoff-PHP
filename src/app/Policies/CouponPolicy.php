<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_coupon');
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $user->can('view_coupon');
    }

    public function create(User $user): bool
    {
        return $user->can('create_coupon');
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $user->can('update_coupon');
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $user->can('delete_coupon');
    }
}
