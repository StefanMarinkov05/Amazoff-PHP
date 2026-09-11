<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user whose account stopped being usable while they
 * were signed in.
 *
 * A session outlives the row it authenticated against. `Login` checks
 * `is_active` as part of the credentials, but that is a check at one instant:
 * deactivating or soft-deleting a customer afterwards left them browsing on a
 * valid session until it expired on its own. `canAccessPanel()` already makes
 * this argument for the panel — this is the same rule for the storefront,
 * where nothing enforced it.
 *
 * Soft-deleted counts as inactive: `User` uses SoftDeletes, so the row
 * survives the delete. The default guard resolves through the model's global
 * scopes and so returns null for a soft-deleted user on its own, but that is
 * the guard's behaviour rather than a decision this application states
 * anywhere, so the check is explicit here instead of relied upon.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && (! $user->is_active || $user->trashed())) {
            return $this->reject($request);
        }

        return $next($request);
    }

    /**
     * Log the user out and send them to the sign-in page.
     *
     * The session is invalidated rather than only logged out: leaving the
     * session data behind would carry a deactivated user's flash state and
     * cart key into whoever signs in next on that browser.
     */
    protected function reject(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A literal rather than __('auth.…'): no lang/ directory is published
        // in this application, so a translation key would render as the key
        // itself on the login page. Translation storage is still an open
        // decision — schema/open-schema-questions.md.
        return redirect()->route('login')
            ->with('status', 'Your account is no longer active. Please contact us if you believe this is a mistake.');
    }
}
