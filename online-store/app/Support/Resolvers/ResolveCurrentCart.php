<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * The visitor's cart if they have one, without opening a new one.
     *
     * For read-only callers — the header badge renders on every page, and
     * `forVisitor()` there would write a row for every visitor and every
     * crawler that ever loaded the site.
     */
    public static function existing(): ?Cart
    {
        return self::query()->first();
    }

    public static function forVisitor(): Cart
    {
        return self::query()->first() ?? Cart::create(self::identity());
    }

    /**
     * How this visitor's cart is identified: by account if signed in, by
     * session otherwise.
     *
     * @return array<string, mixed>
     */
    private static function identity(): array
    {
        $userId = Auth::id();

        return $userId !== null
            ? ['user_id' => $userId]
            : ['session_id' => Session::getId(), 'user_id' => null];
    }

    /**
     * The visitor's *unspent* cart.
     *
     * `whereDoesntHave('order')` is the load-bearing clause, and it is not an
     * optimisation — without it a customer can never buy twice.
     *
     * `CreateOrder` does not delete the cart it consumes: `orders.cart_id` is
     * the audit link from an order back to the basket it came from, and §19
     * needs that to survive. But the row surviving meant this resolver kept
     * handing the same spent cart back to the same session, and
     * `UNIQUE(orders.cart_id)` then refused every subsequent checkout with
     * "Cart N has already been checked out" — permanently, for the life of
     * that session. Confirmed live on 2026-09-05: place one order, add
     * another item, and checkout is dead until the cookie is cleared.
     *
     * Excluding spent carts here rather than deleting them keeps the audit
     * link intact and gives the visitor a fresh cart on their next add — the
     * behaviour a shop is expected to have.
     *
     * @return Builder<Cart>
     */
    private static function query()
    {
        return Cart::query()
            ->where(self::identity())
            ->whereDoesntHave('order');
    }
}
