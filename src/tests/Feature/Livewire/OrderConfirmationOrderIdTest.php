<?php

declare(strict_types=1);

use App\Livewire\Checkout\OrderConfirmation;
use App\Models\Order;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * SEC-014. `$orderId` was `public int`, not `#[Locked]` — the one
 * id-property in the codebase that broke the "every id-property is
 * #[Locked]" convention every sibling (CheckoutPage::$orderId,
 * ProductDetails::$productId/$imageIndex, ManageAddresses::$editingId)
 * follows. A client `$set('orderId', <34-digit>)` threw an uncaught
 * TypeError at hydration, before any component code ran — confirmed live
 * before this fix, not assumed from the type alone. It is internal-only,
 * assigned once in mount() and never legitimately client-set, so #[Locked]
 * closes the crash outright rather than needing a normalising hook.
 */

it('locks orderId against client tampering', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->getKey()]);

    expect(fn () => Livewire::actingAs($user)
        ->test(OrderConfirmation::class, ['order' => $order->getKey()])
        ->set('orderId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not crash on a client-set orderId too large for PHP to represent as an int', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->getKey()]);

    expect(fn () => Livewire::actingAs($user)
        ->test(OrderConfirmation::class, ['order' => $order->getKey()])
        ->set('orderId', '99999999999999999999999999999999'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->not->toThrow(TypeError::class);
});
