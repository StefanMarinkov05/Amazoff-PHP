<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `AddToCart` takes no lock. One owner is not one request: two tabs, a
 * double-clicked button, and a retried request all reach the same cart at the
 * same time, and the read-then-insert around
 * UNIQUE(cart_id, product_variation_id) is a check-then-act window like any
 * other. The fix is CLAUDE.md's idempotency rule — catch the violation, retry
 * as an update — rather than a lock on a row that may not exist yet.
 *
 * Two processes and a barrier, for the reason troubleshooting.md gives under
 * "A concurrency test cannot be written in one process": single-process fault
 * injection proves a boundary, never a lock.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'cart_items',
        'carts',
        'inventory_movements',
        'inventories',
        'product_variations',
        'product_images',
        'product_specifications',
        'products',
        'product_categories',
        'brands',
        'coupons',
        'users',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

/**
 * @return array<int, string> one entry per worker, 'OK' or 'FAILED:<class>'
 */
function raceTwoAddsToOneCart(int $cartId, int $variationId, int $quantity): array
{
    return runRaceWorkers([
        ['action' => 'add-to-cart', 'ids' => [$cartId, $variationId], 'args' => [$quantity]],
        ['action' => 'add-to-cart', 'ids' => [$cartId, $variationId], 'args' => [$quantity]],
    ])->all();
}

it('keeps both of two simultaneous adds of the same variation', function (): void {
    $cart = Cart::factory()->create(['user_id' => User::factory(), 'coupon_id' => null, 'expires_at' => null]);
    $variation = cartVariation(stock: 10);

    $outputs = collect(raceTwoAddsToOneCart($cart->getKey(), $variation->getKey(), 1));
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    // Both workers read "no existing line" before either wrote, so both try to
    // insert; the unique key rejects the second insert, and that process
    // retries as an update against the row its rival just created. Neither
    // process surfaces the collision to the caller.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        2,
        'Expected both adds to succeed. Fewer than two usually means the retry '.
        'was removed and one process saw the exception surface.'.$report,
    );

    $item = CartItem::where('cart_id', $cart->getKey())->sole();

    // The number that matters: two adds of one unit each is 2, not 1. Without
    // the retry the loser's exception would surface and the customer would see
    // an error despite the click having "worked" for the winner.
    expect($item->quantity)->toBe(2, 'One of the two adds was lost.'.$report);
});

it('serialises two adds that do not overlap', function (): void {
    $cart = Cart::factory()->create(['user_id' => User::factory(), 'coupon_id' => null, 'expires_at' => null]);
    $variation = cartVariation(stock: 10);

    // The same two calls, sequentially: the outcome the racing test does not
    // produce, which is what makes the window above a defect rather than the
    // design.
    app(AddToCart::class)->handle($cart, $variation, 1);
    app(AddToCart::class)->handle($cart, $variation, 1);

    expect(CartItem::where('cart_id', $cart->getKey())->sole()->quantity)->toBe(2);
});
