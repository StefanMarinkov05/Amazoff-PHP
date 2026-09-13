<?php

declare(strict_types=1);

use App\Livewire\Account\ManageAddresses;
use App\Models\Address;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * SEC-014. `$editingId` was `?int`, not `#[Locked]`, despite being set only
 * server-side (startAdding()/startEditing()/cancelEditing()/save()/
 * delete()) — confirmed no blade `$set` targets it. A client
 * `$set('editingId', <34-digit>)` threw an uncaught TypeError at hydration,
 * confirmed live before this fix. save()/delete() already owner-scope the
 * lookup through $this->user()->addresses(), so this closes a crash, not an
 * IDOR — the same distinction OrderConfirmationOrderIdTest draws for
 * $orderId.
 */

it('locks editingId against client tampering', function (): void {
    $user = User::factory()->create();
    Address::factory()->create(['user_id' => $user->getKey()]);

    expect(fn () => Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->set('editingId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not crash on a client-set editingId too large for PHP to represent as an int', function (): void {
    $user = User::factory()->create();
    Address::factory()->create(['user_id' => $user->getKey()]);

    expect(fn () => Livewire::actingAs($user)
        ->test(ManageAddresses::class)
        ->set('editingId', '99999999999999999999999999999999'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->not->toThrow(TypeError::class);
});
