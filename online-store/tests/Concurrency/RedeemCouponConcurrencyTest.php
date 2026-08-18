<?php

declare(strict_types=1);

use App\Exceptions\CouponNotApplicableException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Two customers redeeming a coupon at its usage limit simultaneously.
 *
 * Unlike the stock race, there is no CHECK spanning coupons and
 * coupon_redemptions — `misc/coupon-actions-plan.md` says so explicitly, and
 * `2026_08_11_094725_add_check_constraints_to_domain_tables.php`'s own
 * trailing comment lists "coupon usage never exceeds total_usage_limit" as
 * an application invariant the database cannot express. Without
 * lockForUpdate() on the coupons row, both processes read the pre-state
 * count, both pass their own check, and both insert — the winner *count* is
 * the discriminator, not the exception type (PublishProductConcurrencyTest's
 * shape, not ReserveStockConcurrencyTest's).
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'coupon_redemptions',
        'order_items',
        'orders',
        'coupon_product',
        'coupon_product_category',
        'coupons',
        'products',
        'product_categories',
        'brands',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

function limitedCoupon(array $overrides = []): Coupon
{
    return Coupon::factory()->create(array_merge([
        'code' => 'RACE'.bin2hex(random_bytes(6)),
        'type' => 'fixed',
        'scope' => 'entire_order',
        'value' => '10.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => 1,
        'usage_limit_per_customer' => null,
    ], $overrides));
}

function orderWithOneLine(Product $product, string $email): Order
{
    $order = Order::factory()->create([
        'email' => $email,
        'subtotal_amount' => '100.00',
        'discount_amount' => 0,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $product->getKey(),
        'quantity' => 1,
        'unit_price' => '100.00',
        'line_total' => '100.00',
        'discount_amount' => 0,
        'vat_rate' => (string) $product->vat_rate,
    ]);

    return $order;
}

/**
 * @return Collection<int, string>
 */
function runRedeemRace(Coupon $coupon, Order $orderA, Order $orderB): Illuminate\Support\Collection
{
    $script = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $couponId = (int) $argv[1];
        $orderId = (int) $argv[2];
        $startAt = (float) $argv[3];

        Illuminate\Support\Facades\DB::select('SELECT 1');

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }
        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }

        try {
            app(App\Actions\Coupon\RedeemCoupon::class)->handle(
                App\Models\Coupon::findOrFail($couponId),
                App\Models\Order::findOrFail($orderId),
            );
            echo 'OK';
        } catch (Throwable $e) {
            echo 'FAILED:'.get_class($e);
        }
        PHP;

    file_put_contents(base_path('redeem-race-worker.php'), $script);

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
        $processes = collect([$orderA, $orderB])->map(function (Order $order) use ($coupon, $startAt, $env): Process {
            $process = new Process(
                ['php', 'redeem-race-worker.php', (string) $coupon->getKey(), (string) $order->getKey(), (string) $startAt],
                base_path(),
                $env,
            );
            $process->start();

            return $process;
        });

        $processes->each(fn (Process $p) => $p->wait());

        return $processes->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()));
    } finally {
        @unlink(base_path('redeem-race-worker.php'));
    }
}

it('lets exactly one of two different customers redeem a coupon at its total usage limit', function (): void {
    $product = Product::factory()->create();
    $coupon = limitedCoupon(['total_usage_limit' => 1]);
    $orderA = orderWithOneLine($product, 'customer-a@example.com');
    $orderB = orderWithOneLine($product, 'customer-b@example.com');

    $outputs = runRedeemRace($coupon, $orderA, $orderB);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Two means both processes read the '.
        'pre-redemption count and both inserted — check that RedeemCoupon '.
        'still lockForUpdate()s the coupons row before counting.'.$report,
    );

    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.CouponNotApplicableException::class,
        'The loser did not refuse cleanly.'.$report,
    );

    expect(CouponRedemption::where('coupon_id', $coupon->getKey())->count())->toBe(1);
});

it('lets exactly one of two orders from the same customer redeem a coupon at the per-customer limit', function (): void {
    // The two-tabs case: same email_hash, two different orders (a
    // double-submitted "place order"), usage_limit_per_customer = 1.
    $product = Product::factory()->create();
    $coupon = limitedCoupon(['total_usage_limit' => null, 'usage_limit_per_customer' => 1]);
    $orderA = orderWithOneLine($product, 'same-customer@example.com');
    $orderB = orderWithOneLine($product, 'same-customer@example.com');

    $outputs = runRedeemRace($coupon, $orderA, $orderB);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner across two orders from the same '.
        'customer. Two means the per-customer count was read before either '.
        'write committed.'.$report,
    );

    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.CouponNotApplicableException::class,
        'The loser did not refuse cleanly.'.$report,
    );

    expect(CouponRedemption::where('coupon_id', $coupon->getKey())->count())->toBe(1);
});
