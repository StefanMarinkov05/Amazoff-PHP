<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

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
 * @return array<int, string> one entry per worker, 'OK:<quantity>' or 'FAILED:<class>'
 */
function raceTwoAddsToOneCart(int $cartId, int $variationId, int $quantity): array
{
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $cart = App\Models\Cart::findOrFail((int) $argv[1]);
        $variation = App\Models\ProductVariation::findOrFail((int) $argv[2]);
        $quantity = (int) $argv[3];
        $startAt = (float) $argv[4];

        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            $item = app(App\Actions\Cart\AddToCart::class)->handle($cart, $variation, $quantity);
            echo 'OK:'.$item->quantity;
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('cart-race-worker.php'), $script);

    $startAt = microtime(true) + (float) (getenv('RACE_BARRIER_SECONDS') ?: 8.0);

    try {
        $processes = collect(range(1, 2))->map(function () use ($cartId, $variationId, $quantity, $startAt): Process {
            $process = new Process(
                [
                    'php',
                    'cart-race-worker.php',
                    (string) $cartId,
                    (string) $variationId,
                    (string) $quantity,
                    (string) $startAt,
                ],
                base_path(),
                [
                    'DB_CONNECTION' => 'mysql',
                    'DB_DATABASE' => config('database.connections.mysql.database'),
                    'DB_HOST' => config('database.connections.mysql.host'),
                    'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_USERNAME' => config('database.connections.mysql.username'),
                    'DB_PASSWORD' => config('database.connections.mysql.password'),
                ],
            );
            $process->start();

            return $process;
        });

        $processes->each(fn (Process $p) => $p->wait());

        return $processes
            ->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()))
            ->all();
    } finally {
        @unlink(base_path('cart-race-worker.php'));
    }
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
    expect($outputs->filter(fn (string $o) => str_starts_with($o, 'OK:')))->toHaveCount(
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
