<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Exceptions\ProductRequiresVariationException;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Publishing a product while its last variation is being removed.
 *
 * §6–7: every sellable product has at least one variation. Two Actions can
 * break that between them — `UpdateProduct` setting `is_available`, and
 * `RemoveProductVariation` deleting the only variation. Each checks the other's
 * side, and each check is check-then-act:
 *
 *   UpdateProduct            count variations → 1, proceed → save available
 *   RemoveProductVariation   product unavailable, proceed → delete
 *
 * Run concurrently with no lock, both read the pre-state, both decide they are
 * fine, and both commit. The result is an available product with no variation:
 * the invariant is gone and neither Action did anything individually wrong.
 *
 * ## Why this differs from the stock race
 *
 * `ReserveStockConcurrencyTest` cannot assert the winner count, because
 * `chk_inventories_reserved_not_above_current` produces exactly one winner
 * with or without the lock — only the *kind* of failure differs.
 *
 * Here there is no constraint to fall back on. MySQL cannot express "an
 * available product has at least one live variation" across two tables, and
 * ADR-0004 rejected triggers. So the unlocked version really does commit both
 * writes, and **the winner count is the discriminator**: two winners means the
 * lock is gone.
 *
 * ## Why the lock is on `products`
 *
 * Both Actions take `lockForUpdate()` on the product row — the aggregate root
 * — rather than on the rows they modify. Locking `product_variations` would
 * leave the two contending on different rows, and neither would ever wait for
 * the other.
 *
 * Order is `products` before `inventories`, globally. `ReserveStock` takes
 * `inventories` alone and never reaches for a product, so there is no cycle.
 *
 * Outside tests/Feature because RefreshDatabase rolls back rather than
 * commits, and a second connection cannot see uncommitted rows.
 */

afterEach(function (): void {
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

it('refuses one of publish and remove-last-variation rather than losing the invariant', function (): void {
    $product = Product::factory()->create(['is_available' => false]);

    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => 'RACE-'.bin2hex(random_bytes(8)),
        'price' => '19.99',
    ]);

    // Same harness as ReserveStockConcurrencyTest: booted by hand because
    // `artisan tinker <file>` never exits, and spin-waiting on a shared
    // instant because Laravel's boot time dwarfs the window under test.
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $role = $argv[1];
        $id = (int) $argv[2];
        $startAt = (float) $argv[3];

        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            if ($role === 'publish') {
                app(App\Actions\Catalogue\UpdateProduct::class)->handle(
                    App\Models\Product::findOrFail($id),
                    ['is_available' => true],
                );
            } else {
                app(App\Actions\Catalogue\RemoveProductVariation::class)->handle(
                    App\Models\ProductVariation::findOrFail($id),
                );
            }
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('publish-race-worker.php'), $script);

    $startAt = microtime(true) + 3.0;

    $env = [
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => config('database.connections.mysql.password'),
    ];

    try {
        $processes = collect([
            ['publish', $product->getKey()],
            ['remove', $variation->getKey()],
        ])->map(function (array $args) use ($startAt, $env): Process {
            $process = new Process(
                ['php', 'publish-race-worker.php', $args[0], (string) $args[1], (string) $startAt],
                base_path(),
                $env,
            );
            $process->start();

            return $process;
        });

        $processes->each(fn (Process $p) => $p->wait());
        $outputs = $processes->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()));
    } finally {
        @unlink(base_path('publish-race-worker.php'));
    }

    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    // The discriminator. Without the product-row lock both processes read the
    // pre-state, both pass their own check, and both print OK.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Two means both Actions decided on state '.
        'the other invalidated — check that UpdateProduct and '.
        'RemoveProductVariation both lockForUpdate() the products row. '.
        'Neither winning usually means the workers failed to boot.'.$report,
    );

    // Whichever lost, it lost by reading current state and refusing, not by
    // hitting a constraint — there is no constraint here to hit.
    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.ProductRequiresVariationException::class,
        'The loser did not refuse cleanly.'.$report,
    );

    // The invariant itself, whichever order the two landed in.
    $product->refresh();

    expect($product->is_available && $product->productVariations()->count() === 0)->toBeFalse(
        'An available product with no variation is exactly the state the two '.
        'Actions exist to prevent.'.$report,
    );
});
