<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Cart;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The current visitor's cart, created on first use.
 *
 * A signed-in customer's cart is keyed by `user_id`; a guest's by
 * `session_id`. `MergeGuestCart` is what joins the two at login — this only
 * finds or opens one, and deliberately does not merge.
 *
 * `expires_at` is left null. `ExpireCarts` skips null rows, so a cart opened
 * here lives until something sets a deadline on it. That is the current
 * behaviour of the system rather than a decision made here: nothing in `app/`
 * has ever written `expires_at`, and choosing a guest-cart lifetime is a
 * policy call that belongs with the cart page rather than smuggled in
 * underneath a product page's add-to-cart button.
 *
 * A function, not an Action: `firstOrCreate` on a single table enforces no
 * invariant that the schema does not already hold. ADR-0007, ADR-0014.
 */
final class ResolveCurrentCart
{
    public static function forVisitor(): Cart
    {
        $userId = Auth::id();

        if ($userId !== null) {
            return Cart::firstOrCreate(['user_id' => $userId]);
        }

        return Cart::firstOrCreate([
            'session_id' => Session::getId(),
            'user_id' => null,
        ]);
    }
}
