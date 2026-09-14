<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Cart\MergeGuestCart;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Folds the guest cart a visitor arrived with into the account they just
 * authenticated as.
 *
 * `MergeGuestCart` has existed, tested and unused, since the cart slice —
 * including two concurrency tests. What was missing was a caller, and the
 * reason it is worth its own class rather than four lines inlined twice is
 * the ordering below, which is easy to get wrong in a way nothing reports.
 *
 * ## The session id must be captured *before* `session()->regenerate()`
 *
 * A guest cart is keyed on `session_id`. Both `Login` and `Register`
 * regenerate the session immediately after authenticating, and they must —
 * the pre-login session id is what a fixation attack plants, so it cannot
 * survive the privilege change.
 *
 * But regeneration issues a *new* id, and nothing carries the old one
 * forward. A merge attempted afterwards looks up
 * `Cart::where('session_id', Session::getId())` against an id that has never
 * had a cart, finds nothing, and reports success — the customer silently
 * loses their basket. Confirmed live on 2026-09-04 before this existed: an
 * item added as a guest, then a sign-in, produced an empty basket and a
 * `carts` table holding the orphan.
 *
 * So `capture()` runs before regeneration and `apply()` after it. Splitting
 * the two is what makes the ordering explicit at both call sites instead of
 * a comment nobody re-reads.
 *
 * ## Why nothing is thrown
 *
 * A failed merge must not fail the login. Someone who has just proved their
 * identity should be signed in even if their basket cannot be folded in —
 * the alternative is an account locked out by a cart bug. Every branch here
 * therefore degrades to "the user cart as it already was".
 */
final class MergeCartOnAuthentication
{
    /**
     * The guest cart this visitor is carrying, if any, read while the
     * pre-authentication session id is still current.
     *
     * Returns null for the common case — most visitors sign in without a
     * basket — so `apply()` can skip the work entirely.
     */
    public static function capture(): ?Cart
    {
        return Cart::query()
            ->where('session_id', Session::getId())
            ->whereNull('user_id')
            ->first();
    }

    /**
     * Fold the captured cart into the authenticated user's own.
     *
     * Safe to call with null, which is the usual case. Also safe when the
     * guest cart is empty: `MergeGuestCart` deletes it either way, which is
     * the correct outcome — an empty orphan keyed to a session id that no
     * longer exists is a row nothing will ever read again.
     */
    public static function apply(?Cart $guestCart, User $user): void
    {
        if ($guestCart === null) {
            return;
        }

        // Re-read: the row was loaded before the session changed, and a
        // concurrent request (a second tab finishing its own merge) may have
        // deleted it since. `MergeGuestCart` would then merge a stale model.
        $guestCart = Cart::query()->where('id', $guestCart->getKey())->first();

        if ($guestCart === null) {
            return;
        }

        $userCart = Cart::firstOrCreate(['user_id' => $user->getKey()]);

        app(MergeGuestCart::class)->handle($guestCart, $userCart);
    }
}
