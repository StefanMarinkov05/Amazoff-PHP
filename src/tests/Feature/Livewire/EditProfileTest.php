<?php

declare(strict_types=1);

use App\Livewire\Account\EditProfile;
use App\Models\User;
use Livewire\Livewire;

it('redirects a guest to login', function (): void {
    $this->get('/account/profile')->assertRedirect('/login');
});

it('prefills the form from the signed-in user', function (): void {
    $user = User::factory()->create([
        'first_name' => 'Cvetan',
        'last_name' => 'Stoyanov',
        'email' => 'cvetan@example.com',
        'phone' => '+359888000000',
    ]);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->assertSet('first_name', 'Cvetan')
        ->assertSet('last_name', 'Stoyanov')
        ->assertSet('email', 'cvetan@example.com')
        ->assertSet('phone', '+359888000000');
});

it('saves a name, email, and phone change', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('first_name', 'Nikolay')
        ->set('last_name', 'Ivanov')
        ->set('email', 'nikolay.ivanov@example.com')
        ->set('phone', '+359877111222')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect($user->refresh())
        ->first_name->toBe('Nikolay')
        ->last_name->toBe('Ivanov')
        ->email->toBe('nikolay.ivanov@example.com')
        ->phone->toBe('+359877111222');
});

it('clears the phone number when the field is emptied', function (): void {
    $user = User::factory()->create(['phone' => '+359888000000']);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('phone', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->phone)->toBeNull();
});

it('refuses an email already used by another account', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'me@example.com']);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('email', 'taken@example.com')
        ->call('save')
        ->assertHasErrors(['email']);

    expect($user->refresh()->email)->toBe('me@example.com');
});

it('allows saving without changing the email, not tripping on its own address', function (): void {
    $user = User::factory()->create(['email' => 'me@example.com']);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('first_name', 'Updated')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh())->email->toBe('me@example.com')->first_name->toBe('Updated');
});

it('refuses a first name under the minimum length', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('first_name', 'A')
        ->call('save')
        ->assertHasErrors(['first_name']);
});
