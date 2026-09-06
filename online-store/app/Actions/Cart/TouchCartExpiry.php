<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Sets or clears `carts.expires_at` according to who owns the cart. The
 * missing half of `ExpireCarts`, which has had nothing to act on because
 * nothing ever wrote the column.
 *
 * The policy, from `config/cart.php`:
 *
 * - **Guest cart** (`user_id` is null) — expires `guest_ttl_hours` after the
 *   last write. One day. A guest cart is keyed only to a session and is
 *   unreachable once that session is gone, so a longer window only
 *   accumulates rows nobody can return to.
 * - **Registered customer's cart** — never expires. `expires_at` is set back
 *   to null. It is theirs, reachable from any device, and deleting it
 *   silently loses something they can see.
 *
 * Called on every cart write rather than only on creation, so the window
 * slides: a guest still shopping does not lose the cart mid-session. That
 * makes it a *touch*, which is why it is named one.
 *
 * **Clearing on login is the load-bearing case.** `MergeGuestCart` turns a
 * guest cart into a user's; without this call the merged cart keeps the
 * guest expiry and `carts:expire` deletes a registered customer's cart a day
 * later. Nulling the column is not incidental cleanup — it is the difference
 * between a correct merge and silent data loss.
 *
 * No authorization: a cart is single-owner state and this writes only a
 * timestamp derived from the cart's own `user_id`. No lock: `expires_at` is a
 * blind write with no read-then-decide, the same reasoning
 * `SetMainProductImage` gives for taking none.
 * reference/write-rules/cart.md · reference/console-commands.md
 */
final class TouchCartExpiry
{
    public function handle(Cart $cart): Cart
    {
        $expiresAt = null;

        if ($cart->user_id === null) {
            $guestTtlHours = config('cart.guest_ttl_hours');

            if (! is_int($guestTtlHours)) {
                throw new InvalidArgumentException('Config value [cart.guest_ttl_hours] must be an integer.');
            }

            $expiresAt = Carbon::now()->addHours($guestTtlHours);
        }

        $cart->update(['expires_at' => $expiresAt]);

        return $cart->refresh();
    }
}
