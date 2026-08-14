<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * §37 criterion 15 requires a shipment to be creatable from an order, which is
 * why create is granted here where OrderPolicy denies it outright — a shipment
 * is staff work, an order is not.
 *
 * §28's "a shipment cannot be created for an invalid order" is not this
 * policy's question. That is a domain invariant about the order's state, so it
 * belongs in the Action that creates the shipment; this method only answers
 * whether the actor is allowed to try.
 */
class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_shipment');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $user->can('view_shipment');
    }

    public function create(User $user): bool
    {
        return $user->can('create_shipment');
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->can('update_shipment');
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->can('delete_shipment');
    }
}
