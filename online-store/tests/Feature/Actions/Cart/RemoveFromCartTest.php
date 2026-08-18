<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\RemoveFromCart;
use App\Models\CartItem;
use App\Models\Inventory;

/*
 * The one Action in this namespace with nothing to refuse. What is worth
 * asserting is the absence: that removing a line touches only that line, and
 * that it leaves no stock, no cart, and no sibling behind.
 */

it('deletes the line', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 3);

    app(RemoveFromCart::class)->handle($item);

    expect(CartItem::count())->toBe(0)
        ->and($cart->cartItems()->count())->toBe(0);
});

it('deletes the row rather than soft-deleting it', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);

    app(RemoveFromCart::class)->handle($item);

    // A cart line is transient state with no historical value — product-write-
    // rules.md says as much, and the table has no deleted_at to hold one.
    expect(CartItem::withoutGlobalScopes()->count())->toBe(0);
});

it('leaves the other lines alone', function (): void {
    $cart = emptyCart();
    $first = cartVariation(stock: 10);
    $second = cartVariation(stock: 10);
    $keep = app(AddToCart::class)->handle($cart, $first, 2);
    $drop = app(AddToCart::class)->handle($cart, $second, 4);

    app(RemoveFromCart::class)->handle($drop);

    expect($cart->cartItems()->sole()->getKey())->toBe($keep->getKey())
        ->and($keep->fresh()->quantity)->toBe(2);
});

it('leaves the cart itself in place', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);

    app(RemoveFromCart::class)->handle($item);

    // An empty cart is a normal state, not a cart to clean up: the customer is
    // still shopping and the session still points at it.
    expect($cart->fresh())->not->toBeNull();
});

it('releases no stock, because a cart line held none', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 4);

    app(RemoveFromCart::class)->handle($item);

    // Reservation happens at checkout, not at add-to-cart. If that ever
    // changes, this test is where the missing ReleaseStock call shows up.
    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->current_quantity)->toBe(10)
        ->and($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(0);
});

it('removes a line whose variation has since been soft-deleted', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->delete();

    // The line a deleted product leaves behind is exactly the one a customer
    // most needs to get rid of, so removal must not consult the catalogue.
    app(RemoveFromCart::class)->handle($item);

    expect(CartItem::count())->toBe(0);
});

it('frees the variation to be added again', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 3);

    app(RemoveFromCart::class)->handle($item);
    $fresh = app(AddToCart::class)->handle($cart, $variation, 1);

    // UNIQUE(cart_id, product_variation_id) survives the delete only if the row
    // really went — a soft delete here would make the re-add a 500.
    expect($fresh->quantity)->toBe(1)
        ->and($cart->cartItems()->count())->toBe(1);
});
