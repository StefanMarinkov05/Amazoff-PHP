<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Sets a new password from a link `RequestPasswordReset` sent — step two of
 * the two-step flow.
 *
 * No Action, same reasoning `RequestPasswordReset`'s own docblock gives:
 * this delegates to Laravel's own `Password` broker, which is what
 * validates the token (existence, matching email, not expired) before this
 * class's callback ever runs — an invalid or expired token never reaches
 * the point of writing a password, the broker refuses first.
 *
 * The token itself is never trusted as proof of identity beyond "whoever
 * has this link" — email is collected again on this form rather than
 * inferred from the URL alone, because `Password::reset()`'s own
 * `credentials` array is what the broker matches the token against; a
 * token without its paired email is not enough for the broker to validate.
 *
 * Signs the visitor in and invalidates every *other* session for this
 * account once the reset succeeds — the same response `ChangePassword`
 * gives to "someone else may know the old password", which a password
 * reset is exactly the scenario for. Unlike `ChangePassword`, there is no
 * currently-authenticated session to call `Auth::logoutOtherDevices()`
 * from yet, so this signs the visitor in first (`Auth::login()`), then
 * invalidates every other session the normal way.
 */
#[Layout('components.layouts.app')]
class ConfirmPasswordReset extends Component
{
    use ThrottlesSubmissions;

    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $invalid = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:100'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ];
    }

    public function submit(): void
    {
        $validated = $this->validate();

        // Keyed on the submitted email, not IP: this form is already gated
        // by possessing a valid emailed token, so the enumeration concern
        // RequestPasswordReset's own throttle exists for does not apply the
        // same way here — what this limit actually stops is brute-forcing
        // the token itself against one email.
        $this->throttleSubmission('password-reset-confirm|'.Str::lower($validated['email']), 'password');

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();

                event(new PasswordResetEvent($user));

                Auth::login($user);

                // Every other session for this account is invalidated; the
                // one just created by Auth::login() above is not — same
                // shape ChangePassword uses, just reached from a signed-out
                // state instead of a signed-in one.
                Auth::logoutOtherDevices($password);
                session()->regenerate();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Deliberately one generic refusal rather than surfacing the
            // broker's own INVALID_TOKEN/INVALID_USER distinction — the
            // same enumeration reasoning RequestPasswordReset's docblock
            // states: a different message per failure reason would let a
            // visitor learn whether an email exists from a stale or
            // guessed token.
            $this->invalid = true;

            return;
        }

        $this->redirect('/', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.confirm-password-reset');
    }
}
