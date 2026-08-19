<?php

declare(strict_types=1);

namespace App\Actions\Coupon;

use App\Models\Cart;

/**
 * Clears a cart's coupon. Takes only the cart the caller already owns, no
 * coupon id — there is nothing to tamper with. Never refuses, same shape as
 * `RemoveFromCart`.
 *
 * No `?User $actor` and no policy: this writes one cart the caller already
 * owns, same reasoning as `RemoveFromCart` — ownership is checked at the
 * entry point resolving the caller's cart, not here.
 */
final class RemoveCoupon
{
    public function handle(Cart $cart): Cart
    {
        $cart->update(['coupon_id' => null]);

        return $cart->refresh();
    }
}
