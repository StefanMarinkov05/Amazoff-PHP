<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Password change for an already-authenticated user.
 *
 * `current_password` is required even though the session already proves
 * identity: it is what stops an unattended logged-in browser from being
 * turned into a permanent account takeover.
 */
#[Layout('components.layouts.app')]
class ChangePassword extends Component
{
    use ThrottlesSubmissions;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $saved = false;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'max:100', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    public function updatePassword(): void
    {
        $validated = $this->validate();

        // Keyed on the *user*, not the IP: this form takes current_password,
        // so an unthrottled endpoint is an online guessing oracle against an
        // already-authenticated session. The account is the thing under
        // attack, so the account is what the limit protects. SEC-010.
        $this->throttleSubmission('change-password|'.auth()->id(), 'current_password');

        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect('/login', navigate: true);

            return;
        }

        // Cast 'hashed' on the model handles the hashing.
        $user->update(['password' => $validated['password']]);

        // Every other session for this user is invalidated; the current one is
        // re-issued. A password change is the standard response to "someone
        // else may be signed in as me", so it has to end those sessions.
        Auth::logoutOtherDevices($validated['password']);
        session()->regenerate();

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.auth.change-password');
    }
}
