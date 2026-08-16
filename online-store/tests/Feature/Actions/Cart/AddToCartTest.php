<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Inventory\ReserveStock;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\CartItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * §11 lists what the server validates before any cart update: availability,
 * variation, stock, minimum quantity, maximum available quantity, and current
 * price. This file covers the ones AddToCart implements, and pins the two it
 * does not — see the `is_available` tests, which assert today's behaviour
 * rather than §11's.
 *
 * cartVariation() and emptyCart() come from tests/Pest.php.
 */

it('adds a line at the requested quantity', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);

    $item = app(AddToCart::class)->handle($cart, $variation, 3);

    expect($item->quantity)->toBe(3)
        ->and($item->cart_id)->toBe($cart->getKey())
        ->and($item->product_variation_id)->toBe($variation->getKey())
        ->and($cart->cartItems()->count())->toBe(1);
});

it('sums into the existing line rather than adding a second row', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);

    $first = app(AddToCart::class)->handle($cart, $variation, 2);
    $second = app(AddToCart::class)->handle($cart, $variation, 3);

    // UNIQUE(cart_id, product_variation_id) allows one line per variation, so
    // a second add has to be a quantity change. Inserting instead would be a
    // QueryException, not a duplicate row.
    expect($second->getKey())->toBe($first->getKey())
        ->and($second->quantity)->toBe(5)
        ->and($cart->cartItems()->count())->toBe(1);
});

it('keeps two variations of one product on separate lines', function (): void {
    $cart = emptyCart();
    $first = cartVariation(stock: 10);
    $second = cartVariation(stock: 10, variation: ['product_id' => $first->product_id]);

    app(AddToCart::class)->handle($cart, $first, 1);
    app(AddToCart::class)->handle($cart, $second, 1);

    // The unique key is the variation, not the product — a size M and a size L
    // are two lines.
    expect($cart->cartItems()->count())->toBe(2);
});

it('has no price column to go stale', function (): void {
    // §11's price rule expressed as a schema fact: there is nowhere to store a
    // price on a cart line, so nothing can be stale. ResolveVariationPrice is
    // the only answer to "what does this cost", on every read.
    expect(Schema::hasColumn('cart_items', 'price'))->toBeFalse();
});

/*
 * chk_products_min_order_quantity_positive keeps every product's minimum at 1
 * or more, so on an empty cart the minimum check catches every quantity the
 * `< 1` guard catches, and both raise InvalidCartQuantityException. Asserting
 * the class alone therefore stays green with the guard deleted — the message
 * and the negative-delta case below are what tell the two apart.
 */

it('rejects a quantity of zero', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 0))
        ->toThrow(InvalidCartQuantityException::class, 'Quantity must be at least 1');

    expect(CartItem::count())->toBe(0);
});

it('rejects a negative quantity', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, -5))
        ->toThrow(InvalidCartQuantityException::class, 'Quantity must be at least 1');

    expect(CartItem::count())->toBe(0);
});

it('refuses a negative quantity that would leave the line legal', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 5);

    // The case only the `< 1` guard stops: -2 against an existing 5 sums to 3,
    // which clears both the minimum and the stock check. Without the guard,
    // "add to cart" would quietly remove two units.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, -2))
        ->toThrow(InvalidCartQuantityException::class);

    expect($item->fresh()->quantity)->toBe(5);
});

it('fails a non-positive quantity cleanly rather than at the constraint', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);

    // The distinction the guard buys. chk_cart_items_quantity_positive would
    // stop a zero-quantity insert anyway — as a QueryException and a 500, where
    // the customer should see a message about the quantity they typed.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 0))
        ->toThrow(InvalidCartQuantityException::class);

    // And the backstop is real: without the guard the insert reaches it.
    expect(fn () => DB::table('cart_items')->insert([
        'cart_id' => $cart->getKey(),
        'product_variation_id' => $variation->getKey(),
        'quantity' => 0,
    ]))->toThrow(QueryException::class);
});

it('refuses a quantity below the product minimum', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['min_order_quantity' => 6]);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 3))
        ->toThrow(InvalidCartQuantityException::class);

    expect(CartItem::count())->toBe(0);
});

it('measures the minimum against the resulting quantity, not the added one', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['min_order_quantity' => 6]);

    app(AddToCart::class)->handle($cart, $variation, 6);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);

    // Adding 1 to an existing 6 asks whether 7 is legal, not whether 1 is.
    expect($item->quantity)->toBe(7);
});

it('accepts exactly the product minimum', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['min_order_quantity' => 6]);

    $item = app(AddToCart::class)->handle($cart, $variation, 6);

    expect($item->quantity)->toBe(6);
});

it('refuses more than is available', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 5))
        ->toThrow(InsufficientStockException::class);

    expect(CartItem::count())->toBe(0);
});

it('accepts exactly what is available', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);

    $item = app(AddToCart::class)->handle($cart, $variation, 4);

    expect($item->quantity)->toBe(4);
});

it('counts reserved stock as unavailable', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);
    app(ReserveStock::class)->handle($variation, 3, null);

    // available() is current − reserved. Someone else's checkout has already
    // taken three of the four.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 2))
        ->toThrow(InsufficientStockException::class);

    expect(app(AddToCart::class)->handle($cart, $variation, 1)->quantity)->toBe(1);
});

it('measures availability against the resulting quantity on a second add', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);

    app(AddToCart::class)->handle($cart, $variation, 3);

    // 2 more is legal on its own and 5 is not. The line already holding 3 is
    // what makes this an over-add.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 2))
        ->toThrow(InsufficientStockException::class);

    expect($cart->cartItems()->sole()->quantity)->toBe(3);
});

it('treats a variation with no stock row as having nothing available', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 0);
    $variation->inventory()->delete();

    // A variation without its inventory row is half a record — AddProductVariation
    // creates both, but a seeder or a direct insert need not have. Reading it as
    // zero available fails closed.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation->refresh(), 1))
        ->toThrow(InsufficientStockException::class);
});

it('refuses a soft-deleted variation', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $variation->delete();

    // The in-memory model still answers every accessor, which is exactly the
    // case a cart produces: the variation was live when it was added.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    expect(CartItem::count())->toBe(0);
});

it('refuses a variation whose product is soft-deleted', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $variation->product->delete();

    // DeleteProduct soft-deletes the variations with the product, but a product
    // deleted by any other path leaves them live — so the product is re-read
    // rather than inferred from the variation.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    expect(CartItem::count())->toBe(0);
});

it('re-reads the variation instead of trusting the one it was passed', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);

    // The caller's instance is minutes old by the time a customer clicks add.
    // Loading the relation first, then changing the row underneath it through
    // the query builder, leaves the passed-in model claiming a minimum of 1
    // against a product that now requires 6 — so a validation reading
    // $variation->product would let this through.
    $variation->load('product');
    DB::table('products')->where('id', $variation->product_id)->update(['min_order_quantity' => 6]);

    expect($variation->product->min_order_quantity)->toBe(1)
        ->and(fn () => app(AddToCart::class)->handle($cart, $variation, 3))
        ->toThrow(InvalidCartQuantityException::class);
});

/*
 * The retry that resolves the race (§CLAUDE.md's idempotency rule: catch the
 * unique violation, retry as an update) cannot be proven in this file.
 * addOrIncrement() wraps its read-decide-write in DB::transaction(), so a
 * same-process collision injected via a model event lands inside that
 * transaction and is rolled back with it when the unique violation fires —
 * hiding the very collision the test means to force, the same trap
 * troubleshooting.md documents under "A concurrency test cannot be written in
 * one process". `tests/Concurrency/AddToCartConcurrencyTest.php` proves it
 * with two real processes instead.
 */

it('leaves no line behind when the stock check fails after a first add', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);
    app(AddToCart::class)->handle($cart, $variation, 4);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 1))
        ->toThrow(InsufficientStockException::class);

    // The transaction is what guarantees this: the check runs after the
    // existing line is read, and the update is not reached.
    expect($cart->cartItems()->sole()->quantity)->toBe(4);
});

/*
 * §11 names availability first in the list the server validates.
 */

it('refuses to add a deactivated product', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['is_available' => false]);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    expect(CartItem::count())->toBe(0);
});

it('refuses to add a deactivated variation', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, variation: ['is_available' => false]);

    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    expect(CartItem::count())->toBe(0);
});

it('leaves an existing line alone once its product is deactivated', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 2);

    $variation->product->update(['is_available' => false]);

    // This Action guards what it writes, not what is already in the cart —
    // RemoveFromCart is how the customer clears a line that went dead after
    // being added, same as for a soft-deleted product.
    expect(fn () => app(AddToCart::class)->handle($cart, $variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    expect($item->fresh()->quantity)->toBe(2)
        ->and(CartItem::count())->toBe(1);
});
