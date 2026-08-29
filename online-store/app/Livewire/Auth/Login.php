<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

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
