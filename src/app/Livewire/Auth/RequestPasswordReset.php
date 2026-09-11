<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ThrottlesSubmissions;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Requests a password-reset link — step one of the two-step flow,
 * `ConfirmPasswordReset` is step two.
 *
 * No Action: this delegates entirely to Laravel's own `Password` broker
 * (`password_reset_tokens`, already in the schema from the starter kit,
 * never previously used) and `User` already satisfies
 * `CanResetPasswordContract`/`Notifiable` through `Authenticatable` — there
 * is no domain row this writes and no invariant beyond what the broker
 * itself already enforces (one live token per user, expiring after
 * `config('auth.passwords.users.expire')` minutes).
 *
 * **Deliberately shows the same message whether or not the email exists.**
 * `Login`'s own docblock states the same rule for wrong-email vs.
 * wrong-password: one message, so a response cannot be used to enumerate
 * real accounts. `Password::sendResetLink()`'s own return status
 * (`RESET_LINK_SENT` vs. `INVALID_USER`) is read only to decide whether to
 * actually call the broker — never surfaced to the visitor as a different
 * message for either case.
 */
#[Layout('components.layouts.app')]
class RequestPasswordReset extends Component
{
    use ThrottlesSubmissions;

    public string $email = '';

    public bool $sent = false;

    /** @return array<string, string> */
    protected function rules(): array
    {
        return [
            'email' => 'required|string|email:rfc|max:100',
        ];
    }

    public function submit(): void
    {
        $validated = $this->validate();

        // Keyed on IP, not on the submitted email: keying an enumeration
        // defence on the value being enumerated gives an attacker N
        // attempts *each*, per ThrottlesSubmissions' own docblock — same
        // reasoning Register's throttle uses.
        $this->throttleSubmission('password-reset-request|'.$this->requestIp(), 'email', maxAttempts: 5, decaySeconds: 300);

        // The broker's own status is read only to decide what to do next
        // internally, never exposed as a different message — see this
        // class's own docblock.
        Password::sendResetLink(['email' => $validated['email']]);

        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.auth.request-password-reset');
    }
}
