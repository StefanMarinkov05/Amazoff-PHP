<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Carrier;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * warehouse_employee holds viewAny_carrier and nothing else on this model:
 * §3.4 requires picking a courier when creating a shipment, which is not the
 * same as administering one. content_editor is denied the model outright —
 * §3.3 names courier credentials in its deny-list, and while `carriers`
 * currently holds only name, code, and is_active, it is the table those
 * credentials will land on.
 */
class CarrierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_carrier');
    }

    public function view(User $user, Carrier $carrier): bool
    {
        return $user->can('view_carrier');
    }

    public function create(User $user): bool
    {
        return $user->can('create_carrier');
    }

    public function update(User $user, Carrier $carrier): bool
    {
        return $user->can('update_carrier');
    }

    public function delete(User $user, Carrier $carrier): bool
    {
        return $user->can('delete_carrier');
    }
}
