<?php

declare(strict_types=1);

use App\Livewire\Account\DeleteAccount;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * Self-service GDPR erasure (ADR-0019). The Action itself is covered by
 * EraseCustomerTest; this is the guard rail around it — password, typed
 * confirmation, and that the session is actually ended.
 */

function deletableUser(): User
{
    return User::factory()->create(['password' => Hash::make('correct-horse')]);
}

it('erases the account and logs the session out', function (): void {
    $user = deletableUser();
    Order::factory()->for($user)->create(['anonymized_at' => null, 'email' => 'me@example.com']);

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('current_password', 'correct-horse')
        ->set('confirmation', 'DELETE')
        ->call('deleteAccount')
        ->assertRedirect('/');

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(auth()->check())->toBeFalse();
});

it('refuses without the correct current password', function (): void {
    $user = deletableUser();

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('current_password', 'wrong')
        ->set('confirmation', 'DELETE')
        ->call('deleteAccount')
        ->assertHasErrors('current_password');

    expect(User::find($user->id))->not->toBeNull();
});

it('refuses without the typed DELETE confirmation', function (): void {
    $user = deletableUser();

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('current_password', 'correct-horse')
        ->set('confirmation', 'delete')          // wrong case
        ->call('deleteAccount')
        ->assertHasErrors('confirmation');

    expect(User::find($user->id))->not->toBeNull();
});

it('is not reachable by a guest', function (): void {
    $this->get('/account/delete')->assertRedirect('/login');
});
