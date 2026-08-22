<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Exceptions\ProductRequiresVariationException;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    // A too-small barrier hurts this test most: sequential workers produce
    // exactly one winner, so the assertion below would pass while proving
    // nothing about the lock. runRaceWorkers() sets it; RACE_BARRIER_SECONDS
    // raises it on a loaded runner.
    $outputs = runRaceWorkers([
        ['action' => 'publish-product', 'ids' => [$product->getKey()]],
        ['action' => 'remove-variation', 'ids' => [$variation->getKey()]],
    ]);
    $report = raceReport($outputs);

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
