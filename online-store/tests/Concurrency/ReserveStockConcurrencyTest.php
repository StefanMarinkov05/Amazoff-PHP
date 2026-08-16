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
 * Concurrency tests for stock reservation. Why every race test is shaped this
 * way — two processes, a barrier, outside RefreshDatabase — and how to pick
 * the assertion are in `explanation/concurrency-and-locking.md`.
 *
 * Here the assertion is the exception *type*. The lock does not decide whether
 * stock is oversold: `chk_inventories_reserved_not_above_current` and
 * `increment()` make that impossible either way, so both versions produce
 * exactly one winner. It decides how the loser fails — a handled
 * `InsufficientStockException` rather than a `QueryException` and a 500.
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

it('fails the loser of a race cleanly rather than at the database', function (): void {
    $variation = stockedVariation(1);

    // Booted by hand: `artisan tinker <file>` never exits. usleep alone
    // overshoots by milliseconds, so the worker sleeps to just before the
    // instant and busy-waits the rest.
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $variation = App\Models\ProductVariation::findOrFail((int) $argv[1]);
        $startAt = (float) $argv[2];

        // Warm the connection so the barrier is the last thing that happens
        // before the Action, rather than a TCP handshake being it.
        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            app(App\Actions\Inventory\ReserveStock::class)->handle($variation, 1);
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('race-worker.php'), $script);

    $startAt = microtime(true) + (float) (getenv('RACE_BARRIER_SECONDS') ?: 8.0);

    try {
        $processes = collect(range(1, 2))->map(function () use ($variation, $startAt): Process {
            $process = new Process(
                ['php', 'race-worker.php', (string) $variation->getKey(), (string) $startAt],
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
    } finally {
        @unlink(base_path('race-worker.php'));
    }

    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Neither winning usually means the '.
        'workers failed to boot rather than that locking works.'.$report,
    );

    // The assertion that distinguishes a held lock from a missing one. Both
    // produce one winner; only the locked version lets the loser discover it
    // lost by reading, rather than by having the database reject its write.
    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.InsufficientStockException::class,
        'The losing process did not fail cleanly. A QueryException here means '.
        'it read stale availability, passed the check, and was stopped by '.
        'chk_inventories_reserved_not_above_current instead — a 500 where the '.
        'customer should have seen "out of stock". Check that ReserveStock '.
        'still calls lockForUpdate() before reading the quantities.'.$report,
    );

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

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
