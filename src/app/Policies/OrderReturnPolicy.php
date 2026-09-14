<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderReturn;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * The panel side only. A customer never reaches `ReturnResource`; they create
 * and view their own returns through the storefront, scoped via
 * `auth()->user()->orders()` (`RequestReturn` / `OrderDetails`), so there is
 * no per-record ownership branch here the way `OrderPolicy` needs one.
 *
 * `create` is absent from the catalogue — a return is authored by a customer
 * (§ADR-0020, the `product_review` precedent), never in the panel.
 *
 * `update` gates `ReviewReturn` (approve / deny). `refund` gates
 * `RefundReturn` and is administrator-only (ADR-0011, symmetric to
 * `refund_order` / `refund_payment` — moving money is not editing a row).
 */
class OrderReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_return');
    }

    public function view(User $user, OrderReturn $return): bool
    {
        return $user->can('view_return');
    }

    public function update(User $user, OrderReturn $return): bool
    {
        return $user->can('update_return');
    }

    public function delete(User $user, OrderReturn $return): bool
    {
        return $user->can('delete_return');
    }

    public function refund(User $user, OrderReturn $return): bool
    {
        return $user->can('refund_return');
    }
}
