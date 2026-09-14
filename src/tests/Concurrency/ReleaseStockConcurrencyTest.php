<?php

declare(strict_types=1);

use App\Actions\Inventory\ReleaseStock;
use App\Models\Inventory;
use App\Models\ProductVariation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Concurrency test for stock release — `ReleaseStock`'s counterpart to
 * `ReserveStockConcurrencyTest`. Locks for the same reason `ReserveStock`
 * does, per its own docblock: "A release racing a reservation on the same
 * row would otherwise interleave two read-modify-write pairs and lose one of
 * them." `reference/write-rules/concurrency.md` listed `ReleaseStock` under the
 * same mechanism as `ReserveStock` before this file existed, but no test had
 * ever raced it — this is that proof.
 *
 * The assertion shape is exactly `ReserveStockConcurrencyTest`'s. decrement()
 * makes the write itself atomic regardless of the lock — MySQL evaluates
 * `reserved_quantity = reserved_quantity - 1` against the row's current
 * value at execution time, not against whatever either process read in PHP.
 * What the lock decides is whether the *domain check* in `ReleaseStock::handle()`
 * — "is there enough reserved to release?" — runs against current data or a
 * stale snapshot. Unlocked, both processes can read `reserved_quantity = 1`,
 * both pass "1 >= 1", and both decrement: the second process's UPDATE still
 * executes correctly against the row's latest value (0), taking it to -1,
 * which `chk_inventories_reserved_quantity_non_negative` rejects as a
 * `QueryException` — a 500, not the clean `InvalidArgumentException` a
 * caller could act on. Locked, the second process blocks until the first
 * commits, re-reads `reserved_quantity = 0` under the lock, and its own
 * check refuses cleanly.
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

function reservedVariation(int $current, int $reserved): ProductVariation
{
    $variation = ProductVariation::factory()->create();

    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $current,
        'reserved_quantity' => $reserved,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    return $variation;
}

it('fails the loser of a release race cleanly rather than at the database', function (): void {
    $variation = reservedVariation(current: 10, reserved: 1);

    $outputs = runRaceWorkers([
        ['action' => 'release-stock', 'ids' => [$variation->getKey()], 'args' => [1]],
        ['action' => 'release-stock', 'ids' => [$variation->getKey()], 'args' => [1]],
    ]);
    $report = raceReport($outputs);

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Neither winning usually means the '.
        'workers failed to boot rather than that locking works.'.$report,
    );

    // The assertion that distinguishes a held lock from a missing one. Both
    // produce one winner — decrement() is atomic either way — but only the
    // locked version lets the loser discover it lost by reading, rather than
    // by having the database reject its write.
    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:InvalidArgumentException',
        'The losing process did not fail cleanly. A QueryException here means '.
        'it read a stale reserved_quantity, passed its own check, and was '.
        'stopped by chk_inventories_reserved_quantity_non_negative instead — '.
        'check that ReleaseStock still calls lockForUpdate() before reading '.
        'the quantities.'.$report,
    );

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(1);
});

it('lets the second release see the first once it commits', function (): void {
    $variation = reservedVariation(current: 10, reserved: 1);

    app(ReleaseStock::class)->handle($variation, 1, null);

    expect(fn () => app(ReleaseStock::class)->handle($variation, 1, null))
        ->toThrow(InvalidArgumentException::class);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->reserved_quantity)->toBe(0);
});

it('refuses to hold reserved quantity below zero at the database level', function (): void {
    // The backstop. chk_inventories_reserved_quantity_non_negative catches an
    // over-release even if the lock were wrong — but as a QueryException, a
    // 500 rather than a handled refusal. A test that reaches this constraint
    // through ReleaseStock means the lock failed.
    $variation = reservedVariation(current: 10, reserved: 1);

    expect(fn () => DB::table('inventories')
        ->where('product_variation_id', $variation->getKey())
        ->update(['reserved_quantity' => -1]))
        ->toThrow(QueryException::class);
});
