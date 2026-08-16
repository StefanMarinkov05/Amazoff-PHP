<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\UpdateCartItemQuantity;
use App\Actions\Inventory\ReserveStock;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The same §11 list AddToCart validates, asked about an absolute quantity
 * rather than a delta. The interesting difference is what "the requested
 * amount" means: setting the line to 3 asks about 3, where adding 3 asks about
 * 3 plus whatever the line already held.
 *
 * cartVariation() and emptyCart() come from tests/Pest.php.
 */

it('sets the line to the requested quantity', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 2);

    app(UpdateCartItemQuantity::class)->handle($item, 5);

    expect($item->fresh()->quantity)->toBe(5);
});

it('lowers a quantity as readily as it raises one', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 8);

    app(UpdateCartItemQuantity::class)->handle($item, 2);

    // Absolute, not additive — this is the whole reason it is a second Action.
    expect($item->fresh()->quantity)->toBe(2);
});

it('rejects a quantity of zero', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 3);

    // §10: quantity never falls below one. Removing a line is RemoveFromCart's
    // job, and a zero here is a manual entry, not a delete.
    //
    // The message, not just the class: chk_products_min_order_quantity_positive
    // keeps every minimum at 1 or more, so the minimum check below would also
    // reject a zero and also raise InvalidCartQuantityException. Asserting the
    // class alone stays green with this guard deleted.
    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 0))
        ->toThrow(InvalidCartQuantityException::class, 'Quantity must be at least 1');

    expect($item->fresh()->quantity)->toBe(3);
});

it('rejects a negative quantity', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 3);

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, -2))
        ->toThrow(InvalidCartQuantityException::class);

    expect($item->fresh()->quantity)->toBe(3);
});

it('fails a non-positive quantity cleanly rather than at the constraint', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 3);

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 0))
        ->toThrow(InvalidCartQuantityException::class);

    // chk_cart_items_quantity_positive is the backstop the guard keeps the
    // customer away from: without it the UPDATE reaches the database and 500s.
    expect(fn () => DB::table('cart_items')->whereKey($item->getKey())->update(['quantity' => 0]))
        ->toThrow(QueryException::class);
});

it('refuses a quantity below the product minimum', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['min_order_quantity' => 6]);
    $item = app(AddToCart::class)->handle($cart, $variation, 6);

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 3))
        ->toThrow(InvalidCartQuantityException::class);

    expect($item->fresh()->quantity)->toBe(6);
});

it('accepts exactly the product minimum', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['min_order_quantity' => 6]);
    $item = app(AddToCart::class)->handle($cart, $variation, 8);

    app(UpdateCartItemQuantity::class)->handle($item, 6);

    expect($item->fresh()->quantity)->toBe(6);
});

it('refuses more than is available', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);
    $item = app(AddToCart::class)->handle($cart, $variation, 2);

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 5))
        ->toThrow(InsufficientStockException::class);

    expect($item->fresh()->quantity)->toBe(2);
});

it('accepts exactly what is available', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);

    app(UpdateCartItemQuantity::class)->handle($item, 4);

    expect($item->fresh()->quantity)->toBe(4);
});

it('counts reserved stock as unavailable', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    app(ReserveStock::class)->handle($variation, 3, null);

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 2))
        ->toThrow(InsufficientStockException::class);

    expect($item->fresh()->quantity)->toBe(1);
});

it('does not credit the line its own quantity against availability', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 4);
    $item = app(AddToCart::class)->handle($cart, $variation, 4);

    // A cart line holds no stock — nothing is reserved until checkout — so the
    // four already on the line are not subtracted twice. Setting it to 4 again
    // is legal.
    app(UpdateCartItemQuantity::class)->handle($item, 4);

    expect($item->fresh()->quantity)->toBe(4);
});

it('treats a variation with no stock row as having nothing available', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 5);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->inventory()->delete();

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 1))
        ->toThrow(InsufficientStockException::class);
});

it('re-reads the variation instead of trusting the line it was passed', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 2);
    $item->load('productVariation.product');

    DB::table('products')->where('id', $variation->product_id)->update(['min_order_quantity' => 6]);

    // The relation on the caller's line still says 1; the Action's own query
    // is what sees 6.
    expect($item->productVariation->product->min_order_quantity)->toBe(1)
        ->and(fn () => app(UpdateCartItemQuantity::class)->handle($item, 3))
        ->toThrow(InvalidCartQuantityException::class);
});

it('refuses a line whose variation has been soft-deleted', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->delete();

    // The domain exception AddToCart raises, not ModelNotFoundException — the
    // storefront says "no longer available" rather than rendering a 404 for a
    // line the customer is looking at.
    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 2))
        ->toThrow(RemovedFromCatalogueException::class);

    expect($item->fresh()->quantity)->toBe(1);
});

it('refuses a line whose product has been soft-deleted', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->product->delete();

    // The variation itself is still live: deleting a product does not cascade
    // unless DeleteProduct did it. Reading the product with the default scope
    // gives null, and every rule below it then reads a property on null.
    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 2))
        ->toThrow(RemovedFromCatalogueException::class);

    expect($item->fresh()->quantity)->toBe(1);
});

it('refuses to update a line whose product has been deactivated', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->product->update(['is_available' => false]);

    // §11 names availability first among what the server validates before any
    // cart update. Symmetric with AddToCart's refusal — the line stays exactly
    // as it was, unwritable until the customer removes it.
    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 2))
        ->toThrow(RemovedFromCatalogueException::class);

    expect($item->fresh()->quantity)->toBe(1);
});

it('refuses to update a line whose variation has been deactivated', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    $item = app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->update(['is_available' => false]);

    expect(fn () => app(UpdateCartItemQuantity::class)->handle($item, 2))
        ->toThrow(RemovedFromCatalogueException::class);

    expect($item->fresh()->quantity)->toBe(1);
});
