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
 * §6–7: every sellable product has at least one variation. `UpdateProduct`
 * and `RemoveProductVariation` each guard the other's side, and each check is
 * check-then-act, so unlocked they both read the pre-state and both commit —
 * leaving an available product with nothing to sell.
 *
 * **The winner count is the discriminator here**, unlike the stock race: no
 * CHECK constraint can span two tables, so without the lock both processes
 * genuinely succeed. Both Actions lock `products`, the aggregate root, because
 * locking the rows they each write would leave them contending on different
 * rows and waiting for nothing. ADR-0008.
 *
 * Harness and assertion choice: `explanation/concurrency-and-locking.md`.
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
    ], 0, null);

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
                    null,
                );
            } else {
                app(App\Actions\Catalogue\RemoveProductVariation::class)->handle(
                    App\Models\ProductVariation::findOrFail($id),
                    null,
                );
            }
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('publish-race-worker.php'), $script);

    // This test is the one a too-small barrier hurts most: sequential workers
    // produce exactly one winner, so the assertion below would pass while
    // proving nothing about the lock.
    $startAt = microtime(true) + (float) (getenv('RACE_BARRIER_SECONDS') ?: 8.0);

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
