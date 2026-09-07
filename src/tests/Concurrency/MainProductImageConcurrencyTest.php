<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    ], null);
    $second = app(AddProductImage::class)->handle($product, [
        'path' => 'product-images/b.jpg',
        'sort_order' => 1,
    ], null);

    $outputs = runRaceWorkers([
        ['action' => 'set-main-image', 'ids' => [$first->getKey()]],
        ['action' => 'set-main-image', 'ids' => [$second->getKey()]],
    ]);
    $report = raceReport($outputs);

    // Both are legitimate operations, so both should report success. Neither
    // succeeding means the workers failed to boot rather than that locking
    // works — see how-to/troubleshooting/concurrency-and-testing-races.md on
    // this suite's load sensitivity.
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
