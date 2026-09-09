<?php

declare(strict_types=1);

use App\Livewire\Account\ManageAddresses;
use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('redirects a guest to login', function (): void {
    $this->get('/account/addresses')->assertRedirect('/login');
});

it('lists only the signed-in customer own addresses', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Address::factory()->create(['user_id' => $user->getKey(), 'city' => 'Sofia']);
    Address::factory()->create(['user_id' => $other->getKey(), 'city' => 'Plovdiv']);

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->assertSee('Sofia')
        ->assertDontSee('Plovdiv');
});

it('adds a new address scoped to the signed-in customer', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->call('startAdding')
        ->set('first_name', 'Ivan')
        ->set('last_name', 'Petrov')
        ->set('phone', '+359888111222')
        ->set('country', 'BG')
        ->set('city', 'Varna')
        ->set('postcode', '9000')
        ->set('street', 'Knyaz Boris I 15')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertSet('editingId', null);

    $address = Address::query()->where('user_id', $user->getKey())->sole();

    expect($address->first_name)->toBe('Ivan')
        ->and($address->city)->toBe('Varna')
        ->and($address->user_id)->toBe($user->getKey());
});

it('refuses an incomplete address and writes nothing', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->call('startAdding')
        ->set('first_name', 'Ivan')
        ->call('save')
        ->assertHasErrors(['last_name', 'phone', 'city', 'postcode', 'street']);

    expect(Address::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
});

it('edits an existing address', function (): void {
    $user = User::factory()->create();
    $address = Address::factory()->create(['user_id' => $user->getKey(), 'city' => 'Sofia']);

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->call('startEditing', $address->getKey())
        ->assertSet('city', 'Sofia')
        ->set('city', 'Burgas')
        ->call('save')
        ->assertHasNoErrors();

    expect($address->refresh()->city)->toBe('Burgas');
});

it('cannot edit another customer address by guessing its id', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = Address::factory()->create(['user_id' => $other->getKey()]);

    // startEditing() scopes through $user->addresses()->findOrFail(), never
    // Address::findOrFail() — the exception below is that scoping actually
    // refusing the lookup, not the component crashing some other way.
    expect(fn () => Livewire::actingAs($user)->test(ManageAddresses::class)->call('startEditing', $theirs->getKey()))
        ->toThrow(ModelNotFoundException::class);
});

it('deletes an address', function (): void {
    $user = User::factory()->create();
    $address = Address::factory()->create(['user_id' => $user->getKey()]);

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->call('delete', $address->getKey());

    expect(Address::find($address->getKey()))->toBeNull();
});

it('cannot delete another customer address by guessing its id', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = Address::factory()->create(['user_id' => $other->getKey()]);

    expect(fn () => Livewire::actingAs($user)->test(ManageAddresses::class)->call('delete', $theirs->getKey()))
        ->toThrow(ModelNotFoundException::class);

    expect(Address::find($theirs->getKey()))->not->toBeNull();
});

it('clears the previous default billing address when a new one is marked default', function (): void {
    $user = User::factory()->create();
    $first = Address::factory()->create([
        'user_id' => $user->getKey(),
        'is_default_billing' => true,
    ]);

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->call('startAdding')
        ->set('first_name', 'Second')
        ->set('last_name', 'Address')
        ->set('phone', '+359888333444')
        ->set('country', 'BG')
        ->set('city', 'Sofia')
        ->set('postcode', '1000')
        ->set('street', 'Vitosha 1')
        ->set('is_default_billing', true)
        ->call('save')
        ->assertHasNoErrors();

    $second = Address::query()->where('user_id', $user->getKey())->where('id', '!=', $first->getKey())->sole();

    expect($first->refresh()->is_default_billing)->toBeFalse()
        ->and($second->is_default_billing)->toBeTrue();
});

it('does not clear a default shipping address when only billing changes', function (): void {
    $user = User::factory()->create();
    $shipping = Address::factory()->create([
        'user_id' => $user->getKey(),
        'is_default_billing' => false,
        'is_default_shipping' => true,
    ]);

    Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->call('startAdding')
        ->set('first_name', 'Billing')
        ->set('last_name', 'Only')
        ->set('phone', '+359888555666')
        ->set('country', 'BG')
        ->set('city', 'Sofia')
        ->set('postcode', '1000')
        ->set('street', 'Vitosha 2')
        ->set('is_default_billing', true)
        ->set('is_default_shipping', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($shipping->refresh()->is_default_shipping)->toBeTrue();
});
