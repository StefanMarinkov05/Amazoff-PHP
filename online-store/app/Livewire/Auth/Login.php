<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\MergeCartOnAuthentication;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Storefront sign-in — the only login in the application.
 *
 * Filament's own `->login()` is deliberately disabled in
 * `AdminPanelProvider`: both forms authenticated the same `web` guard, so a
 * second one was a second password surface to audit for no gain. Staff sign
 * in here and reach the panel through the header link, which
 * `canAccessPanel()` gates.
 *
 * No Action: authentication writes no domain row and holds no invariant an
 * Action could own — `Auth::attempt()` is the framework's, and ADR-0007's
 * test ("a rule exists when a write spans more than one table or enforces an
 * invariant the schema cannot express") is not met.
 *
 * `Auth::attempt()` reads the row once, at the instant of the attempt, with
 * `is_active` folded into the same query as the credentials rather than
 * checked afterwards — so a deactivation or a password change by another
 * session has no window to race against: whichever way it lands, the
 * decision is made on current data, not stale data read earlier and acted on
 * later. This is why login itself needs no lock and no test beyond the
 * ordinary success/failure cases — unlike a stale *session*, which is what
 * `EnsureAccountIsActive` and `AuthenticateSession` exist to catch (see
 * `how-to/troubleshooting/auth-and-sessions.md`,
 * "`Auth::logoutOtherDevices()` is called, and other sessions stay signed
 * in").
 *
 * A soft-deleted user cannot log in either, though nothing here states it:
 * `User` uses `SoftDeletes`, whose global scope excludes trashed rows from
 * every Eloquent query, `Auth::attempt()`'s included — confirmed directly
 * with `Auth::attempt()` against a freshly soft-deleted row, not assumed.
 * The exclusion is Eloquent's own guarantee rather than a rule this class
 * enforces, so there is no test pinning it here: pinning framework
 * behaviour that isn't ours is exactly what `CLAUDE.md`'s testing
 * philosophy excludes. If a soft-deleted user is ever queried here through
 * `withTrashed()` for some unrelated reason, this guarantee breaks
 * silently — that is the one thing to check first if a deleted account is
 * ever reported able to sign in.
 */
#[Layout('components.layouts.app')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /** @return array<string, string> */
    protected function rules(): array
    {
        return [
            'email' => 'required|string|email:rfc|max:100',
            'password' => 'required|string',
        ];
    }

    public function login(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        // is_active is checked as part of the credentials rather than after a
        // successful attempt: a deactivated employee should get the same
        // failure as a wrong password, not a different one that confirms the
        // address exists.
        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
            'is_active' => true,
        ];

        // Read before Auth::attempt and before session()->regenerate(): a
        // guest cart is keyed on session_id, and regeneration issues a new
        // one with nothing carrying the old forward. See
        // MergeCartOnAuthentication's docblock for the basket this silently
        // lost before it existed.
        $guestCart = MergeCartOnAuthentication::capture();

        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // One message for both wrong-email and wrong-password: naming
            // which half failed turns the form into an account enumerator.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // Unconditional: the pre-login session id is what a fixation attack
        // plants, so it must not survive the privilege change.
        session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        MergeCartOnAuthentication::apply($guestCart, $user);

        $this->redirectIntended(default: '/catalogue', navigate: true);
    }

    /**
     * Five attempts per email+IP per minute. Keyed on both so one attacker
     * cannot lock a real customer out by hammering their address.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
