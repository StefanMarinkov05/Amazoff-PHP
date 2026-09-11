<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Actions\Gdpr\EraseCustomer;
use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Self-service GDPR Art. 17 erasure (ADR-0019).
 *
 * `current_password` is required even though the session proves identity —
 * the same reason `ChangePassword` requires it: this is irreversible, and
 * an unattended signed-in browser should not be able to trigger it.
 *
 * No permission check: a customer erasing their own data needs no
 * permission, and `EraseCustomer` is called with no actor so it does not
 * run `UserPolicy::erase`. The Filament path is the one that authorises.
 *
 * On success the account is gone, so there is nowhere to redirect *to* as
 * this user — the session is flushed and the visitor lands on the home page
 * with a confirmation flash.
 */
#[Layout('components.layouts.app')]
class DeleteAccount extends Component
{
    use ThrottlesSubmissions;

    public string $current_password = '';

    /** Typed confirmation, so the button is never a one-click accident. */
    public string $confirmation = '';

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'confirmation' => ['required', 'string', 'in:DELETE'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'confirmation.in' => 'Type DELETE in capitals to confirm.',
        ];
    }

    public function deleteAccount(EraseCustomer $erase): void
    {
        $this->validate();

        // Keyed on the user, not the IP — same reasoning as ChangePassword:
        // this form takes current_password, so it is a guessing oracle
        // against an authenticated session if left unthrottled. SEC-010.
        $this->throttleSubmission('delete-account|'.auth()->id(), 'current_password');

        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect('/login', navigate: true);

            return;
        }

        $erase->handle($user);

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        session()->flash('success', 'Your account and personal data have been deleted.');

        $this->redirect('/', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.account.delete-account');
    }
}
