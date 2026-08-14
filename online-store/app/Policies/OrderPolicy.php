<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * The first policy with a per-record half. Every other policy so far covers a
 * lookup table, where "may this user edit brands" is the whole question. An
 * order has an owner, so §34's "prevention of unauthorized resource access"
 * applies: a customer reads their own order and nobody else's.
 *
 * Staff with viewAny_order see every order, which is the point of the panel.
 * A customer holds no order permissions at all and reaches their own orders
 * through the ownership branch instead. CLAUDE.md still requires the query to
 * be scoped (`auth()->user()->orders()->findOrFail($id)`) rather than relying
 * on this check alone — a policy denies access to a record already loaded,
 * scoping stops it being loaded at all.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_order');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('view_order') || $order->user_id === $user->id;
    }

    /**
     * Nobody creates an order in the panel. An order exists because a
     * customer completed checkout (§12), which runs through the storefront
     * and its Actions. create_order is not in the permission catalogue.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can('update_order');
    }

    /**
     * Orders are never deleted — §19 requires the history to survive, and
     * §26 lets a customer re-order from a past one. Cancellation is a status
     * transition, not a delete. delete_order is not in the catalogue.
     */
    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    /**
     * §17: not every employee may select every status. Which transitions are
     * legal at all is a separate question answered by OrderStatus itself, and
     * recording the change is a third — see ADR-0004. This method answers only
     * whether this actor may attempt one.
     */
    public function updateStatus(User $user, Order $order): bool
    {
        return $user->can('updateStatus_order');
    }

    /** §18: internal notes are staff-only and never shown to the customer. */
    public function addInternalNote(User $user, Order $order): bool
    {
        return $user->can('addInternalNote_order');
    }
}
