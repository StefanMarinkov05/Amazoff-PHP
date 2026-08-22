<?php

declare(strict_types=1);

use App\Actions\Cart\ExpireCarts;
use App\Models\Cart;
use App\Models\Order;

it('deletes a cart past its expires_at', function (): void {
    $cart = Cart::factory()->create(['expires_at' => now()->subDay()]);

    $deleted = app(ExpireCarts::class)->handle();

    expect($deleted)->toBe(1)
        ->and(Cart::query()->find($cart->getKey()))->toBeNull();
});

it('cascades the cart_items of a deleted cart', function (): void {
    $variation = variationWithStock(current: 5);
    $cart = Cart::factory()->create(['expires_at' => now()->subDay()]);
    $cart->cartItems()->create(['product_variation_id' => $variation->getKey(), 'quantity' => 1]);

    app(ExpireCarts::class)->handle();

    expect($cart->cartItems()->count())->toBe(0);
});

it('leaves a cart whose expires_at is in the future', function (): void {
    $cart = Cart::factory()->create(['expires_at' => now()->addDay()]);

    app(ExpireCarts::class)->handle();

    expect(Cart::query()->find($cart->getKey()))->not->toBeNull();
});

it('leaves a cart that never expires', function (): void {
    $cart = Cart::factory()->create(['expires_at' => null]);

    app(ExpireCarts::class)->handle();

    expect(Cart::query()->find($cart->getKey()))->not->toBeNull();
});

it('refuses to delete an expired cart that already produced an order', function (): void {
    // Proven by contrast: the sibling test above shows an identical cart
    // does get deleted absent this row, so this one failing to delete is
    // the guard acting, not a coincidence of the query.
    $cart = Cart::factory()->create(['expires_at' => now()->subDay()]);
    Order::factory()->create(['cart_id' => $cart->getKey()]);

    $deleted = app(ExpireCarts::class)->handle();

    expect($deleted)->toBe(0)
        ->and(Cart::query()->find($cart->getKey()))->not->toBeNull();
});

it('reports how many it deleted, not just whether it ran', function (): void {
    Cart::factory()->count(3)->create(['expires_at' => now()->subDay()]);
    Cart::factory()->create(['expires_at' => now()->addDay()]);

    $deleted = app(ExpireCarts::class)->handle();

    expect($deleted)->toBe(3);
});
