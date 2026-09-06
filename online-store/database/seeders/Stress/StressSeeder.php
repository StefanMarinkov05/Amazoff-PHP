<?php

declare(strict_types=1);

namespace Database\Seeders\Stress;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Database\Seeders\Demo\DemoOrderSeeder;
use InvalidArgumentException;

/**
 * Table-size data for query-plan and pagination testing, nothing else. Not
 * demo history, never presented, never wired into `DatabaseSeeder`, never
 * run in CI.
 *
 * A thin wrapper over `DemoOrderSeeder`'s per-order engine
 * (`loadPools()` + `seedOneOrder()`), reusing exactly those two rather than
 * reimplementing the cart-build-and-walk sequence. A separate factory-based
 * fast path would produce orders that reserve no stock and whose inventory
 * rows disagree with their own items — a corrupt dataset that looks fine
 * until someone actually queries it. `DemoOrderSeeder`'s own docblock
 * explains why fabricated orders are the trap this avoids.
 *
 * Deliberately loose on distribution precision where `DemoOrderSeeder`
 * is exact: no coupon assignment, no guest-order ratio, and each order's
 * walk takes the simplest legal path to a randomly chosen target status
 * rather than the brief's slot-assigned origin variety. None of that
 * precision matters for table size; it would only slow this down for
 * nothing this class needs.
 *
 * Run explicitly:
 *
 *     php artisan db:seed --class="Database\Seeders\Stress\StressSeeder"
 *     STRESS_SEED_COUNT=5000 php artisan db:seed --class="Database\Seeders\Stress\StressSeeder"
 *
 * (Artisan's `db:seed` does not forward custom options by itself — see
 * `run()`'s fallback to the `STRESS_SEED_COUNT` env var for the practical
 * way to override the count without a custom command.)
 */
class StressSeeder extends DemoOrderSeeder
{
    private const DEFAULT_COUNT = 2000;

    /**
     * The protected-SKU exclusion applies here exactly as it does in
     * `DemoOrderSeeder` — `loadPools()` is inherited unchanged and already
     * filters through `ProtectedSkus`, so there is nothing extra to do here
     * beyond calling it.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $configuredCount = config('stress.order_count');

        if ($configuredCount !== null && ! is_scalar($configuredCount)) {
            throw new InvalidArgumentException('Config value [stress.order_count] must be a scalar value.');
        }

        $count = (int) ($configuredCount ?? self::DEFAULT_COUNT);

        if ($count < 1) {
            $this->command?->warn('StressSeeder: count must be at least 1, got '.$count.'. Skipping.');

            return;
        }

        $this->loadPools();

        $started = microtime(true);
        $created = 0;
        $failed = 0;

        foreach ($this->buildStressPlan($count) as $spec) {
            try {
                $this->seedOneOrder($spec);
                $created++;
            } catch (\Throwable) {
                $failed++;
            }

            if ($created > 0 && $created % 200 === 0) {
                $this->command?->info("StressSeeder: {$created}/{$count} orders created...");
            }
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->command?->info(
            "StressSeeder: created {$created} order(s), {$failed} failed, in {$elapsed}s."
        );

        if ($elapsed > 300) {
            $this->command?->warn(
                "StressSeeder took {$elapsed}s for {$created} orders — worth reporting as a finding "
                .'if this is meant to run routinely rather than as a one-off.'
            );
        }
    }

    /**
     * $count orders, each targeting a uniformly random terminal-ish status
     * via the simplest legal walk to it, split evenly between Stripe and
     * COD. No coupon, no guest/registered ratio — see the class docblock
     * for why that precision isn't reproduced here.
     *
     * @return iterable<array{target: string, origins: list<string>, method: PaymentMethod, guest: bool, coupon: string|null}>
     */
    private function buildStressPlan(int $count): iterable
    {
        $targets = [
            OrderStatus::New,
            OrderStatus::AwaitingPayment,
            OrderStatus::Paid,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::ReadyForShipment,
            OrderStatus::Shipped,
            OrderStatus::Delivered,
            OrderStatus::Cancelled,
            OrderStatus::Returned,
            OrderStatus::Refunded,
        ];

        for ($i = 0; $i < $count; $i++) {
            $method = $i % 2 === 0 ? PaymentMethod::Stripe : PaymentMethod::CashOnDelivery;
            $target = $targets[array_rand($targets)];

            // AwaitingPayment/Paid are Stripe-only targets, same constraint
            // DemoOrderSeeder::buildPlan() enforces — force Stripe here
            // rather than risking an illegal COD walk.
            if (in_array($target, [OrderStatus::AwaitingPayment, OrderStatus::Paid], true)) {
                $method = PaymentMethod::Stripe;
            }

            yield [
                'target' => $target->value,
                'origins' => $this->simplestWalkTo($target, $method),
                'method' => $method,
                'guest' => $i % 5 === 0,
                'coupon' => null,
            ];
        }
    }

    /**
     * The one, simplest legal path to $target — no origin variety, unlike
     * `DemoOrderSeeder::originsFor()`. Table size doesn't care which of
     * several legal paths got a row there.
     *
     * @return list<string>
     */
    private function simplestWalkTo(OrderStatus $target, PaymentMethod $method): array
    {
        $isCod = $method === PaymentMethod::CashOnDelivery;
        $preConfirmed = $isCod ? ['new'] : ['new', 'awaiting_payment', 'paid'];

        return match ($target) {
            OrderStatus::New => ['new'],
            OrderStatus::AwaitingPayment => ['new', 'awaiting_payment'],
            OrderStatus::Paid => ['new', 'awaiting_payment', 'paid'],
            OrderStatus::Confirmed => [...$preConfirmed, 'confirmed'],
            OrderStatus::Preparing => [...$preConfirmed, 'confirmed', 'preparing'],
            OrderStatus::ReadyForShipment => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment'],
            OrderStatus::Shipped => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped'],
            OrderStatus::Delivered => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered'],
            OrderStatus::Cancelled => ['new', 'cancelled'],
            OrderStatus::Returned => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'returned'],
            OrderStatus::Refunded => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'returned', 'refunded'],
        };
    }
}
