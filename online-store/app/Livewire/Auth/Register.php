<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\User;
use App\Support\MergeCartOnAuthentication;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
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
    use ThrottlesSubmissions;

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

        // Keyed on IP with a wider window than login's: registration is
        // rarer than sign-in, and the thing being limited is bulk account
        // creation rather than guessing. SEC-010.
        $this->throttleSubmission('register|'.$this->requestIp(), 'email', maxAttempts: 5, decaySeconds: 600);

        // Before session()->regenerate() below — a guest cart is keyed on
        // session_id, and regeneration issues a new one. See
        // MergeCartOnAuthentication's docblock.
        $guestCart = MergeCartOnAuthentication::capture();

        // The unique rule above and this catch are two halves of one check,
        // not a redundancy: between validating and inserting, another
        // registration can take the same address. Catch-and-convert rather
        // than check-then-act, the same rule CLAUDE.md states for
        // idempotency — the index is what actually decides, so the loser is
        // told here in the form's own language instead of getting a 500.
        try {
            // 'password' is cast 'hashed' on the model, so no Hash::make here —
            // doing both would hash the hash.
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Deliberately the same wording the unique rule produces, so the
            // race and the ordinary case are indistinguishable to the user.
            throw ValidationException::withMessages([
                'email' => __('validation.unique', ['attribute' => 'email']),
            ]);
        }

        event(new Registered($user));

        Auth::login($user);

        session()->regenerate();

        MergeCartOnAuthentication::apply($guestCart, $user);

        $this->redirect('/catalogue', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
