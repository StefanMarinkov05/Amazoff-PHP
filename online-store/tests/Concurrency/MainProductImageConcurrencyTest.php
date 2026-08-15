<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Two administrators promoting different images of one product.
 *
 * The third assertion shape in `explanation/concurrency-and-locking.md`:
 * `SetMainProductImage` is a blind single-statement write, so there is no
 * mechanism to delete and no interleaving that can leave two rows set. The
 * assertion is the final state as a property, not a guard. Both operations
 * are legitimate, so both should report success.
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

it('leaves exactly one main image when two are promoted at once', function (): void {
    $product = Product::factory()->create();

    $first = app(AddProductImage::class)->handle($product, [
        'path' => 'product-images/a.jpg',
        'sort_order' => 0,
    ]);
    $second = app(AddProductImage::class)->handle($product, [
        'path' => 'product-images/b.jpg',
        'sort_order' => 1,
    ]);

    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $id = (int) $argv[1];
        $startAt = (float) $argv[2];

        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            app(App\Actions\Catalogue\SetMainProductImage::class)->handle(
                App\Models\ProductImage::findOrFail($id),
            );
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('main-image-race-worker.php'), $script);

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
        $processes = collect([$first->getKey(), $second->getKey()])
            ->map(function (int $id) use ($startAt, $env): Process {
                $process = new Process(
                    ['php', 'main-image-race-worker.php', (string) $id, (string) $startAt],
                    base_path(),
                    $env,
                );
                $process->start();

                return $process;
            });

        $processes->each(fn (Process $p) => $p->wait());
        $outputs = $processes->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()));
    } finally {
        @unlink(base_path('main-image-race-worker.php'));
    }

    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    // Both are legitimate operations, so both should report success. Neither
    // succeeding means the workers failed to boot rather than that locking
    // works — see troubleshooting.md on this suite's load sensitivity.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(2, $report);

    $mains = ProductImage::where('product_id', $product->getKey())
        ->where('is_main', true)
        ->count();

    expect($mains)->toBe(
        1,
        'A product with images has exactly one main image. Two means the '.
        'promotion is no longer a single statement — check that '.
        'SetMainProductImage still writes is_main = (id = N) in one UPDATE.'.$report,
    );
});
