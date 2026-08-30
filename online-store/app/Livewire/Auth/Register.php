<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Customer registration.
 *
 * The account created here holds **no role at all** — not a `customer` role.
 * A registered customer is the default authenticated state, and their access
 * to their own orders is an ownership check in a policy, not a permission
 * (§3, and the note in `UserSeeder`). Assigning a role here would also hand
 * anyone who registers whatever that role later accrues.
 *
 * No Action: one INSERT into one table, same test that keeps `ContactForm`
 * and the lookup tables off Actions. ADR-0007.
 */
#[Layout('components.layouts.app')]
class Register extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'first_name' => 'required|string|min:2|max:50',
            'last_name' => 'required|string|min:2|max:50',
            // Uniqueness is enforced by the users.email unique index too; this
            // rule is the readable half, the index is the binding one.
            'email' => ['required', 'string', 'email:rfc', 'max:100', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function updated(string $property): void
    {
        $this->validateOnly($property);
    }

    public function register(): void
    {
        $validated = $this->validate();

        // 'password' is cast 'hashed' on the model, so no Hash::make here —
        // doing both would hash the hash.
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        event(new Registered($user));

        Auth::login($user);

        session()->regenerate();

        $this->redirect('/catalogue', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
