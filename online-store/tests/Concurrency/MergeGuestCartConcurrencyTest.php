<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\MergeGuestCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * `MergeGuestCart` takes no lock, for the same reason `AddToCart` does not:
 * one login is not one request, and the read-then-insert around
 * UNIQUE(cart_id, product_variation_id) is the same check-then-act window
 * whoever reaches it — a retried login request re-merging the same guest
 * cart, or a merge landing on the user's cart at the same instant an
 * unrelated `AddToCart` does. The fix is CLAUDE.md's idempotency rule: catch
 * the violation, retry as an update, never a lock on a row that may not
 * exist yet.
 *
 * Two processes and a barrier, for the reason troubleshooting.md gives under
 * "A concurrency test cannot be written in one process": single-process
 * fault injection proves a boundary, never a lock.
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
function raceTwoMergesOfOneGuestCart(int $guestCartId, int $userCartId): array
{
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $guest = App\Models\Cart::findOrFail((int) $argv[1]);
        $user = App\Models\Cart::findOrFail((int) $argv[2]);
        $startAt = (float) $argv[3];

        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            app(App\Actions\Cart\MergeGuestCart::class)->handle($guest, $user);
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('merge-race-worker.php'), $script);

    $startAt = microtime(true) + (float) (getenv('RACE_BARRIER_SECONDS') ?: 8.0);

    try {
        $processes = collect(range(1, 2))->map(function () use ($guestCartId, $userCartId, $startAt): Process {
            $process = new Process(
                [
                    'php',
                    'merge-race-worker.php',
                    (string) $guestCartId,
                    (string) $userCartId,
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
        @unlink(base_path('merge-race-worker.php'));
    }
}

it('folds two simultaneous merges of the same guest cart cleanly', function (): void {
    $guest = Cart::factory()->create(['user_id' => null, 'coupon_id' => null, 'expires_at' => null]);
    $user = Cart::factory()->create(['user_id' => User::factory(), 'coupon_id' => null, 'expires_at' => null]);
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($guest, $variation, 1);

    // Both workers race to fold the same guest line into the same user cart —
    // a retried login, or two tabs completing login at once.
    $outputs = collect(raceTwoMergesOfOneGuestCart($guest->getKey(), $user->getKey()));
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        2,
        'Expected both merges to succeed. Fewer than two usually means the '.
        'retry was removed and one process saw the collision surface.'.$report,
    );

    $item = CartItem::where('cart_id', $user->getKey())->sole();

    expect($item->quantity)->toBe(2, 'One of the two merges was lost.'.$report);

    expect(Cart::whereKey($guest->getKey())->exists())->toBeFalse(
        'The guest cart should not survive either merge.'.$report,
    );
});

it('serialises two merges that do not overlap', function (): void {
    $user = Cart::factory()->create(['user_id' => User::factory(), 'coupon_id' => null, 'expires_at' => null]);
    $variation = cartVariation(stock: 10);

    $firstGuest = Cart::factory()->create(['user_id' => null, 'coupon_id' => null, 'expires_at' => null]);
    $secondGuest = Cart::factory()->create(['user_id' => null, 'coupon_id' => null, 'expires_at' => null]);
    app(AddToCart::class)->handle($firstGuest, $variation, 1);
    app(AddToCart::class)->handle($secondGuest, $variation, 1);

    // The same collision, sequentially — the outcome that makes the window
    // above a defect rather than the design.
    app(MergeGuestCart::class)->handle($firstGuest, $user);
    app(MergeGuestCart::class)->handle($secondGuest, $user);

    expect(CartItem::where('cart_id', $user->getKey())->sole()->quantity)->toBe(2);
});
