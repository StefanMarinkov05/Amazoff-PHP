<?php

declare(strict_types=1);

use App\Livewire\Auth\ChangePassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * What these prove is ours, not Laravel's: that a deactivated account cannot
 * sign in even with the right password, that a failed login names neither
 * half of the credentials, that registration produces a *roleless* customer
 * who cannot reach the panel, and that a password change requires the current
 * password. Auth::attempt() and Password::defaults() are the framework's and
 * are not re-tested here.
 */

it('signs in an active user with correct credentials', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);
});

it('refuses a deactivated user holding the correct password', function (): void {
    $user = User::factory()->create(['is_active' => false]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('reports a wrong password against the email field, naming neither half', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $component = Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'not-the-password')
        ->call('login');

    // The error lands on `email`, and says only that the pair failed. An error
    // on `password` would confirm the address exists — an account enumerator.
    $component->assertHasErrors('email')->assertHasNoErrors('password');
    expect(auth()->check())->toBeFalse();
});

it('refuses an oversized login attempt rather than hashing it against the real password unbounded', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', str_repeat('a', 101))
        ->call('login')
        ->assertHasErrors('password');

    expect(auth()->check())->toBeFalse();
});

it('registers a customer holding no role, who cannot reach the panel', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    Livewire::test(Register::class)
        ->set('first_name', 'New')
        ->set('last_name', 'Customer')
        ->set('email', 'new.customer@example.test')
        ->set('password', 'Str0ng!Passw0rd')
        ->set('password_confirmation', 'Str0ng!Passw0rd')
        ->call('register')
        ->assertHasNoErrors();

    $user = User::where('email', 'new.customer@example.test')->sole();

    expect($user->getRoleNames())->toBeEmpty()
        ->and($user->is_active)->toBeTrue()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and(auth()->id())->toBe($user->id);
});

it('stores the registration password hashed exactly once', function (): void {
    Livewire::test(Register::class)
        ->set('first_name', 'Hash')
        ->set('last_name', 'Check')
        ->set('email', 'hash.check@example.test')
        ->set('password', 'Str0ng!Passw0rd')
        ->set('password_confirmation', 'Str0ng!Passw0rd')
        ->call('register')
        ->assertHasNoErrors();

    // The model casts `password` to 'hashed'. Calling Hash::make() in the
    // component as well would hash the hash and make the password unusable.
    expect(Hash::check('Str0ng!Passw0rd', User::where('email', 'hash.check@example.test')->value('password')))
        ->toBeTrue();
});

it('refuses a duplicate registration email', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::test(Register::class)
        ->set('first_name', 'Dup')
        ->set('last_name', 'Licate')
        ->set('email', 'taken@example.test')
        ->set('password', 'Str0ng!Passw0rd')
        ->set('password_confirmation', 'Str0ng!Passw0rd')
        ->call('register')
        ->assertHasErrors('email');
});

it('refuses an oversized registration password rather than hashing it unbounded', function (): void {
    // Password::defaults() enforces a minimum length but nothing in
    // Laravel caps the maximum; bcrypt still processes the whole string up
    // to its own 72-byte truncation, so an unbounded password is a cheap
    // hashing-cost lever on an unauthenticated endpoint. Group B2's
    // free-text sweep.
    $password = str_repeat('a', 101).'1A!';

    Livewire::test(Register::class)
        ->set('first_name', 'Too')
        ->set('last_name', 'Long')
        ->set('email', 'too.long@example.test')
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('register')
        ->assertHasErrors('password');

    expect(User::where('email', 'too.long@example.test')->exists())->toBeFalse();
});

it('refuses a password change without the current password', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->set('current_password', 'wrong-current')
        ->set('password', 'An0ther!Passw0rd')
        ->set('password_confirmation', 'An0ther!Passw0rd')
        ->call('updatePassword')
        ->assertHasErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('changes the password when the current one is given', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->set('current_password', 'password')
        ->set('password', 'An0ther!Passw0rd')
        ->set('password_confirmation', 'An0ther!Passw0rd')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect(Hash::check('An0ther!Passw0rd', $user->fresh()->password))->toBeTrue();
});

it('refuses an oversized new password on a change rather than hashing it unbounded', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $password = str_repeat('a', 101).'1A!';

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->set('current_password', 'password')
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('updatePassword')
        ->assertHasErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('gates the panel by role, not by being signed in', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $panel = Filament::getPanel('admin');
    $customer = User::factory()->create(['is_active' => true]);
    $staff = User::factory()->create(['is_active' => true]);
    $staff->assignRole('content_editor');

    expect($customer->canAccessPanel($panel))->toBeFalse()
        ->and($staff->canAccessPanel($panel))->toBeTrue();
});
