<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * No create: a payment row is written by the Stripe webhook (§13) or by the
 * COD flow, never by hand, so create_payment is not in the catalogue.
 *
 * §3.3 denies content_editor payment information by name. That denial is
 * enforced by the role holding no payment permissions rather than by anything
 * here — this class would return false for them regardless of which role they
 * held, which is the point of checking permissions instead of roles.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_payment');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('view_payment');
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Manual correction — a COD remittance marked paid by hand. */
    public function update(User $user, Payment $payment): bool
    {
        return $user->can('update_payment');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->can('delete_payment');
    }

    /**
     * Separate from update because refunding money is not editing a row.
     * Whether a refund is possible at all — amount available, Stripe state —
     * is the Action's question, not this one.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->can('refund_payment');
    }
}
