<?php

declare(strict_types=1);

use App\Livewire\Cart\CartBadge;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * The header's cart count. CartBadge's own job over ResolveCurrentCart and
 * CartItem: never opening a cart just to render a count (existing(), not
 * forVisitor()), summing quantities across lines rather than counting rows,
 * the 9+ display cap, and refreshing on the cart-updated event the rest of
 * the storefront dispatches.
 */

function cartWithQuantity(int $quantity, ?User $owner = null): Cart
{
    $cart = Cart::factory()->create([
        'user_id' => $owner?->getKey(),
        'session_id' => $owner === null ? Session::getId() : null,
        'coupon_id' => null,
        'expires_at' => null,
    ]);

    $cart->cartItems()->create([
        'product_variation_id' => cartVariation()->getKey(),
        'quantity' => $quantity,
    ]);

    return $cart;
}

it('shows zero for a visitor with no cart at all', function (): void {
    $component = Livewire::test(CartBadge::class);

    expect($component->instance()->count())->toBe(0)
        ->and($component->html())->not->toContain('wire:key="badge-');
});

it('does not create a cart row just by rendering', function (): void {
    expect(Cart::query()->count())->toBe(0);

    Livewire::test(CartBadge::class);

    expect(Cart::query()->count())->toBe(0);
});

it('sums quantity across every line, not the number of lines', function (): void {
    $cart = cartWithQuantity(2);
    $cart->cartItems()->create([
        'product_variation_id' => cartVariation()->getKey(),
        'quantity' => 3,
    ]);

    expect(Livewire::test(CartBadge::class)->instance()->count())->toBe(5);
});

it('caps the displayed label at 9+ past the display cap, while count stays exact', function (): void {
    cartWithQuantity(12);

    $component = Livewire::test(CartBadge::class);

    expect($component->instance()->count())->toBe(12)
        ->and($component->html())->toContain('>9+<')
        ->and($component->html())->not->toContain('>12<');
});

it('does not cap the label at exactly the display cap', function (): void {
    cartWithQuantity(9);

    $component = Livewire::test(CartBadge::class);

    expect($component->instance()->count())->toBe(9)
        ->and($component->html())->toContain('>9<')
        ->and($component->html())->not->toContain('9+');
});

it('refreshes its count when a cart-updated event is dispatched', function (): void {
    $cart = cartWithQuantity(1);

    $component = Livewire::test(CartBadge::class);
    expect($component->instance()->count())->toBe(1);

    $cart->cartItems()->create([
        'product_variation_id' => cartVariation()->getKey(),
        'quantity' => 4,
    ]);

    // The badge has no way to know the line was added except the event —
    // it reads no request input of its own, so without the listener this
    // would still show the stale count from mount.
    $component->dispatch('cart-updated');

    expect($component->instance()->count())->toBe(5);
});
