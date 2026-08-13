<?php

declare(strict_types=1);

use App\Actions\Inventory\ReserveStock;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\ProductVariation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Concurrency tests for stock reservation.
 *
 * These assert the invariant §20 requires — never more reserved than exists,
 * one ledger row per successful reservation — under two real OS processes,
 * because the race is between two connections and one PHP process holding one
 * connection cannot produce it.
 *
 * **Known limitation, do not trust this file as a regression guard.** Deleting
 * `lockForUpdate()` from ReserveStock leaves all three tests green. Verified
 * by doing it. The window between the availability check and the write is
 * microseconds wide and two sequentially-started processes do not reliably
 * land inside it, so the race the lock prevents is not reliably reproduced.
 *
 * Two earlier designs failed the same way for different reasons, both worth
 * recording because both looked correct:
 *
 *   1. Taking the lock with a raw `DB::select ... FOR UPDATE` and watching a
 *      second connection block. That tests MySQL's FOR UPDATE, not this
 *      codebase.
 *   2. Holding the lock elsewhere and asserting ReserveStock throws 1205. The
 *      Action's `increment` and its ledger insert block on the holder anyway,
 *      so the timeout arrives with or without the lock, just from a later
 *      statement.
 *
 * What would actually close this: a barrier both workers wait on so they
 * enter the critical section together — a shared advisory lock, or a
 * `sleep(0)` injected between the read and the write under a test flag. Both
 * put test-only machinery in production code, which is why neither is here
 * yet. Tracked in misc/open-review-findings.md.
 *
 * The lock is verified by hand: with another session holding the row FOR
 * UPDATE, a plain SELECT returns stale data in 0.00s and a locked SELECT
 * blocks until timeout. That is the behaviour the Action relies on.
 *
 * This file lives outside tests/Feature because RefreshDatabase wraps each
 * test in an uncommitted transaction whose rows no other connection can see.
 */

afterEach(function (): void {
    // Truncated with foreign keys disabled rather than deleted in dependency
    // order: the order is a property of whatever the factories happen to
    // create today, so a hand-maintained list breaks the moment a factory
    // gains a relation.
    Schema::disableForeignKeyConstraints();

    foreach ([
        'inventory_movements',
        'inventories',
        'product_variations',
        'product_images',
        'product_specifications',
        'products',
        'product_categories',
        'brands',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

function stockedVariation(int $quantity): ProductVariation
{
    $variation = ProductVariation::factory()->create();

    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $quantity,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    return $variation;
}

it('lets exactly one of two racing processes reserve the last item', function (): void {
    $variation = stockedVariation(1);

    // Two real OS processes, because the race is between two connections and
    // one PHP process holding one connection cannot produce it. Each prints
    // OK or FAILED so the parent can count winners.
    //
    // Booted by hand rather than through `artisan tinker <file>`, which stays
    // interactive and never exits — the workers then produce no output at all
    // and the test fails for a reason that has nothing to do with locking.
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $variation = App\Models\ProductVariation::findOrFail((int) $argv[1]);

        try {
            app(App\Actions\Inventory\ReserveStock::class)->handle($variation, 1);
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED';
        }
        PHP;

    file_put_contents(base_path('race-worker.php'), $script);

    try {
        $processes = collect(range(1, 2))->map(function () use ($variation): Process {
            $process = new Process(
                ['php', 'race-worker.php', (string) $variation->getKey()],
                base_path(),
                // phpunit.xml points this suite at online_shop_test; a bare
                // PHP process reads .env instead, which is the dev database.
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

        $outputs = $processes->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()));
        $winners = $outputs->filter(fn (string $o) => str_contains($o, 'OK'));

        expect($winners)->toHaveCount(
            1,
            'Expected exactly one of two racing processes to reserve the last '.
            "item. Worker output was:\n".$outputs->implode("\n---\n").
            "\n\nWith lockForUpdate() absent both processes read available=1 ".
            'before either wrote, so both proceed and stock is oversold. '.
            'Neither winning usually means the workers failed to boot rather '.
            'than that locking works.',
        );
    } finally {
        @unlink(base_path('race-worker.php'));
    }

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    // The invariant that matters, whatever the processes did: never more
    // reserved than exists, and exactly one ledger row for the one winner.
    expect($inventory->reserved_quantity)->toBe(1)
        ->and($inventory->available())->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(1);
});

it('lets the second reservation see the first once it commits', function (): void {
    $variation = stockedVariation(1);

    // Sequential rather than racing: asserts the outcome the lock produces,
    // where the test above asserts the lock is doing the producing.
    app(ReserveStock::class)->handle($variation, 1);

    expect(fn () => app(ReserveStock::class)->handle($variation, 1))
        ->toThrow(InsufficientStockException::class);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->reserved_quantity)->toBe(1)
        ->and($inventory->available())->toBe(0);
});

it('refuses to hold more reserved than current at the database level', function (): void {
    // The backstop. chk_inventories_reserved_not_above_current catches an
    // over-reservation even if the lock were wrong — but as a QueryException,
    // a 500 rather than a handled "out of stock". A test that reaches this
    // constraint through ReserveStock means the lock failed.
    $variation = stockedVariation(2);

    expect(fn () => DB::table('inventories')
        ->where('product_variation_id', $variation->getKey())
        ->update(['reserved_quantity' => 3]))
        ->toThrow(QueryException::class);
});
