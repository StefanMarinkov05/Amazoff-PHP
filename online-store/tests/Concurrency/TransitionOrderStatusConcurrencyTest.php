<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderStatusTransitionException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Two staff transitioning one order at once — the case
 * reference/write-rules/concurrency.md listed as "Not covered" until this
 * file. Proves TransitionOrderStatus's own lockForUpdate() on `orders`
 * decides which of two concurrent transitions wins, and that the loser fails
 * cleanly — IllegalOrderStatusTransitionException, from re-reading the
 * locked row and finding its own target no longer legal — rather than at the
 * database. UNIQUE(order_id, new_status) is the backstop, not the mechanism;
 * reaching it as a QueryException means the lock failed.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'order_status_histories',
        'order_items',
        'orders',
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

function statusRaceOrder(OrderStatus $status, int $quantity = 2): Order
{
    $variation = ProductVariation::factory()->create();

    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 10,
        'reserved_quantity' => $quantity,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    $order = Order::factory()->create(['status' => $status]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return $order->fresh();
}

/**
 * Runs one `TransitionOrderStatus::handle()` call per (order id, target
 * status) pair, in a separate process each, released together at a shared
 * barrier instant.
 *
 * @param  list<array{0: int, 1: OrderStatus}>  $jobs
 * @return Collection<int, string>
 */
function runOrderStatusRaceWorkers(array $jobs): Collection
{
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $orderId = (int) $argv[1];
        $to = App\Enums\OrderStatus::from($argv[2]);
        $startAt = (float) $argv[3];

        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            app(App\Actions\Order\TransitionOrderStatus::class)->handle(
                App\Models\Order::findOrFail($orderId),
                $to,
                null,
            );
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('order-status-race-worker.php'), $script);

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
        $processes = collect($jobs)->map(function (array $job) use ($startAt, $env): Process {
            [$orderId, $to] = $job;

            $process = new Process(
                ['php', 'order-status-race-worker.php', (string) $orderId, $to->value, (string) $startAt],
                base_path(),
                $env,
            );
            $process->start();

            return $process;
        });

        $processes->each(fn (Process $p) => $p->wait());

        return $processes->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()));
    } finally {
        @unlink(base_path('order-status-race-worker.php'));
    }
}

it('makes two identical concurrent transitions idempotent: both succeed, exactly one write happens', function (): void {
    $order = statusRaceOrder(OrderStatus::New);

    $outputs = runOrderStatusRaceWorkers([
        [$order->getKey(), OrderStatus::Confirmed],
        [$order->getKey(), OrderStatus::Confirmed],
    ]);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    // Both calls report success. This is not ReserveStockConcurrencyTest's
    // shape — there the loser is refused; here the second call is a
    // recognized no-op (decision 3: from === to means "already done," safe
    // only because OrderStatus's graph is acyclic). What proves the lock is
    // doing anything is not what each process sees but that the write
    // happened exactly once.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        2,
        'Expected both calls to report success — a double-submitted identical '.
        'transition is a no-op, not a refusal.'.$report,
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->orderStatusHistories()->count())->toBe(
            1,
            'Expected exactly one history row. Two (or a QueryException hitting '.
            'UNIQUE(order_id, new_status)) means the second call did not see the '.
            "first one's write when it re-read under the lock — check that the ".
            'no-op check reads the locked row rather than the $order instance '.
            'passed into handle().'.$report,
        );
});

it('fails the loser of two different concurrent transitions cleanly, and only the winner sticks', function (): void {
    // AwaitingPayment and Confirmed are both legal from New, and neither is
    // reachable from the other — a genuine mutual exclusion. Not every pair
    // sharing an origin has this property: from Paid, both Confirmed and
    // Cancelled are legal, but Cancelled is *also* legal from Confirmed — so
    // racing those two would sometimes let both succeed, sequentially, which
    // is correct behaviour rather than a bug and would make a fixed-outcome
    // assertion wrong more than it would prove anything. Checked pairwise
    // per pairing used, not assumed from Cancelled's general reachability.
    $order = statusRaceOrder(OrderStatus::New);

    $outputs = runOrderStatusRaceWorkers([
        [$order->getKey(), OrderStatus::AwaitingPayment],
        [$order->getKey(), OrderStatus::Confirmed],
    ]);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(1, $report);
    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.IllegalOrderStatusTransitionException::class,
        $report,
    );

    $finalStatus = $order->fresh()->status;

    expect([OrderStatus::AwaitingPayment, OrderStatus::Confirmed])->toContain($finalStatus)
        ->and($order->orderStatusHistories()->count())->toBe(1)
        ->and($order->orderStatusHistories()->sole()->new_status)->toBe($finalStatus);
});

it('fails the loser cleanly when a cancellation and a shipment race, and releases stock only if cancel won', function (): void {
    // The business-relevant version of the pair above, not just the
    // mechanism: a warehouse employee ships an order the instant an
    // administrator cancels it. From ReadyForShipment, Shipped and
    // Cancelled are both legal, and — unlike Paid's Confirmed/Cancelled
    // pair — neither is reachable from the other: Shipped allows only
    // Delivered/Returned, Cancelled allows only Refunded. A genuine mutual
    // exclusion, and the one that actually matters to a customer.
    $variation = ProductVariation::factory()->create();
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 5,
        'reserved_quantity' => 2,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);
    $order = Order::factory()->create(['status' => OrderStatus::ReadyForShipment]);
    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
        'quantity' => 2,
    ]);

    $outputs = runOrderStatusRaceWorkers([
        [$order->getKey(), OrderStatus::Cancelled],
        [$order->getKey(), OrderStatus::Shipped],
    ]);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(1, $report);
    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.IllegalOrderStatusTransitionException::class,
        $report,
    );

    $finalStatus = $order->fresh()->status;
    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect([OrderStatus::Cancelled, OrderStatus::Shipped])->toContain($finalStatus)
        ->and($order->orderStatusHistories()->count())->toBe(1)
        // Whichever won, the inventory effect must match — released if
        // cancelled, moved to sold if shipped, never both and never neither.
        ->and($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->current_quantity)->toBe($finalStatus === OrderStatus::Shipped ? 3 : 5)
        ->and($inventory->sold_quantity)->toBe($finalStatus === OrderStatus::Shipped ? 2 : 0);
});
