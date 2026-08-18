<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * An inventory row exists because a variation exists — §20 puts one row per
 * variation — so create and delete are lifecycle events of the variation, not
 * separate acts. They stay in the catalogue because an administrator
 * correcting a missing row is plausible; warehouse_employee holds neither.
 *
 * §20 also requires stock to change through movements rather than direct
 * quantity writes. update_inventory therefore authorizes recording a
 * movement, not editing current_quantity by hand — which the Action enforces,
 * since a policy cannot tell one write from the other.
 */
class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_inventory');
    }

    public function view(User $user, Inventory $inventory): bool
    {
        return $user->can('view_inventory');
    }

    public function create(User $user): bool
    {
        return $user->can('create_inventory');
    }

    public function update(User $user, Inventory $inventory): bool
    {
        return $user->can('update_inventory');
    }

    public function delete(User $user, Inventory $inventory): bool
    {
        return $user->can('delete_inventory');
    }
}
