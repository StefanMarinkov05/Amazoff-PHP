<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Editing the signed-in customer's own name, email, and phone.
 *
 * No Action: one UPDATE on one table with no invariant the schema cannot
 * express — same test that keeps `Register` and `ContactForm` off Actions.
 * ADR-0007.
 *
 * `password` is deliberately not editable here — `ChangePassword` owns
 * that, gated behind `current_password` re-entry, which this form has no
 * reason to also require for a name or phone edit.
 *
 * No email-verification flow exists in this codebase (`User` does not
 * implement `MustVerifyEmail`), so an email change here is not treated any
 * differently from a name change — nothing re-verifies it, because nothing
 * verifies it at registration either. Introducing verification is a
 * separate, deliberate feature, not something this form should invent as a
 * side effect.
 */
#[Layout('components.layouts.app')]
class EditProfile extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public bool $saved = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $user = $this->user();

        return [
            'first_name' => 'required|string|min:2|max:50',
            'last_name' => 'required|string|min:2|max:50',
            // Same shape as Register's own rule; unique against every
            // *other* user, not this one, so saving without changing the
            // email does not trip on the row's own address.
            'email' => [
                'required', 'string', 'email:rfc', 'max:100',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'phone' => 'nullable|string|max:30',
        ];
    }

    public function updated(string $property): void
    {
        $this->validateOnly($property);
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->user()->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] !== '' ? $validated['phone'] : null,
        ]);

        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.account.edit-profile');
    }

    /**
     * The route is behind `auth` middleware, so `auth()->user()` cannot be
     * null here — narrowed explicitly rather than asserted with a docblock,
     * same reasoning `OrderHistory` gives for its own instanceof check.
     */
    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
