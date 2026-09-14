<?php

declare(strict_types=1);

use App\Models\OrderReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Two return requests for the same order line, at the same moment (ADR-0020).
 *
 * `RequestReturn` reads how much of a line has already been returned, then
 * writes a new `returns` row — a check-then-act window. The `lockForUpdate()`
 * on `orders` is the whole defence: without it both requests read
 * "0 already returned", both pass the `quantity <= remaining` guard, and a
 * line ends up with more units returned than were ever ordered — which later
 * lets `RefundReturn` credit back stock that was never sold.
 *
 * The discriminator is how the loser fails: a handled
 * `ReturnNotAllowedException`, never a raw `QueryException`.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'return_items', 'returns', 'order_status_histories', 'order_items', 'orders',
        'inventory_movements', 'inventories', 'product_variations', 'products',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

it('lets the two requests over-return a line only if the lock is removed', function (): void {
    // Line quantity 3. Each side asks for 2 — fine alone, 4 together.
    $order = deliveredOrderForReturn(quantity: 3);
    $line = $order->orderItems->first();

    $outputs = runRaceWorkers([
        ['action' => 'request-return', 'ids' => [$order->getKey(), $line->id], 'args' => ['2']],
        ['action' => 'request-return', 'ids' => [$order->getKey(), $line->id], 'args' => ['2']],
    ]);

    $returnedUnits = (int) DB::table('return_items')
        ->join('returns', 'returns.id', '=', 'return_items.return_id')
        ->where('returns.order_id', $order->getKey())
        ->sum('return_items.quantity');

    // Never more than was ordered.
    expect($returnedUnits)->toBeLessThanOrEqual(3, raceReport($outputs));

    // Exactly one request wrote a row; the other was refused cleanly.
    expect(OrderReturn::where('order_id', $order->getKey())->count())
        ->toBe(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'ReturnNotAllowedException')))
        ->toHaveCount(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'QueryException')))
        ->toHaveCount(0, raceReport($outputs));
});
