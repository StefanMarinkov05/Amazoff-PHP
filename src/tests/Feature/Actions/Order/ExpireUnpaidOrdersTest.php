<?php

declare(strict_types=1);

use App\Actions\Order\ExpireUnpaidOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;

/*
 * ADR-0022 — the abandoned-checkout sweep. A customer reaches Stripe
 * Elements, closes the tab, and nothing else happens: without this the
 * reserved units are unsellable forever.
 *
 * What each test here has to prove is *ours*, not the framework's: that the
 * query selects the right orders (and, just as importantly, refuses the
 * wrong ones), and that cancelling through TransitionOrderStatus actually
 * returns the stock. The release itself is ADR-0011's structural guarantee
 * and has its own tests; what is new here is that this sweep reaches it.
 */

/**
 * An order sitting at `AwaitingPayment`, holding `$quantity` reserved units,
 * whose `AwaitingPayment` history row is `$minutesAgo` old.
 *
 * The history row is the load-bearing part: `ExpireUnpaidOrders` ages an
 * order off that row's `created_at`, not off `orders.created_at`, so a
 * helper that only backdated the order would test nothing.
 */
function unpaidOrder(int $minutesAgo, int $quantity = 2, PaymentMethod $method = PaymentMethod::Stripe): Order
{
    $variation = variationWithStock(current: 10, reserved: $quantity);
    $order = orderWithVariationLine($variation, OrderStatus::AwaitingPayment, $quantity);
    $order->update(['payment_method' => $method, 'anonymized_at' => null]);

    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => OrderStatus::New,
        'new_status' => OrderStatus::AwaitingPayment,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);

    return $order->fresh();
}

function reservedFor(Order $order): int
{
    /** @var ProductVariation $variation */
    $variation = $order->orderItems()->first()->productVariation;

    return Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity;
}

it('cancels an order left unpaid past the TTL and releases its stock', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);
    $order = unpaidOrder(minutesAgo: 11);

    expect(reservedFor($order))->toBe(2);

    $cancelled = app(ExpireUnpaidOrders::class)->handle();

    expect($cancelled)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        // The whole point. ADR-0011 puts the release inside
        // TransitionOrderStatus, so reaching Cancelled is what frees it —
        // this sweep composes no ReleaseStock call of its own.
        ->and(reservedFor($order))->toBe(0);
});

it('leaves an order that is still inside the TTL alone', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);
    $order = unpaidOrder(minutesAgo: 3);

    expect(app(ExpireUnpaidOrders::class)->handle())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(reservedFor($order))->toBe(2);
});

/*
 * The single most important refusal in this file.
 *
 * A cash-on-delivery order sits at `New` and is waiting for *staff*, not for
 * a payment. Sweeping it would cancel live business. This is also the test
 * that pins ADR-0022 decision 1: before the Stripe path moved to
 * AwaitingPayment, an abandoned card order was at `New` too, and no query
 * could have told the two apart.
 */
it('never touches a cash-on-delivery order waiting at New, however old', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);

    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $order->update(['payment_method' => PaymentMethod::CashOnDelivery, 'anonymized_at' => null]);
    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => null,
        'new_status' => OrderStatus::New,
        'created_at' => now()->subDays(30),
    ]);

    expect(app(ExpireUnpaidOrders::class)->handle())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::New)
        ->and(reservedFor($order))->toBe(2);
});

it('leaves an order that was paid before the sweep ran', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);

    // Old enough to be swept if status alone were not checked: the order
    // moved on to Paid, so the AwaitingPayment history row is still ancient
    // but the order is no longer a candidate.
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Paid, 2);
    $order->update(['anonymized_at' => null]);
    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => OrderStatus::New,
        'new_status' => OrderStatus::AwaitingPayment,
        'created_at' => now()->subHours(3),
    ]);

    expect(app(ExpireUnpaidOrders::class)->handle())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

/*
 * Ages off the history row, not orders.created_at. Proven by contrast: the
 * order row itself is ancient while the payment step was reached seconds
 * ago, which is exactly the customer who spent twenty minutes on the address
 * form. Reading the wrong column would cancel them mid-payment.
 */
it('measures the age from the AwaitingPayment transition, not the order row', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);

    $order = unpaidOrder(minutesAgo: 1);
    $order->update(['created_at' => now()->subHours(5)]);

    expect(app(ExpireUnpaidOrders::class)->handle())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('writes a history row naming why the order was cancelled', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);
    $order = unpaidOrder(minutesAgo: 11);

    app(ExpireUnpaidOrders::class)->handle();

    $row = $order->orderStatusHistories()->where('new_status', OrderStatus::Cancelled)->sole();

    expect($row->previous_status)->toBe(OrderStatus::AwaitingPayment)
        // §19: the history says who and why. The sweep is the system, so
        // user_id is null and the reason has to carry the explanation.
        ->and($row->user_id)->toBeNull()
        ->and($row->reason)->toContain('Payment not completed');
});

it('sweeps several expired orders and reports how many', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);
    unpaidOrder(minutesAgo: 11);
    unpaidOrder(minutesAgo: 45);
    unpaidOrder(minutesAgo: 2);

    expect(app(ExpireUnpaidOrders::class)->handle())->toBe(2)
        ->and(Order::where('status', OrderStatus::Cancelled)->count())->toBe(2)
        ->and(Order::where('status', OrderStatus::AwaitingPayment)->count())->toBe(1);
});

/*
 * ── Two layers, pinned separately ───────────────────────────────────────
 *
 * `ExpireUnpaidOrders` refuses a non-AwaitingPayment order twice: the query
 * does not select it, and the loop re-checks `fresh()->status` before
 * transitioning. That redundancy is deliberate — the re-check closes the
 * window where a webhook pays an order between the query and the
 * transition — but it means neither layer can be falsified by outcome
 * alone: delete either one and the other still produces the right answer,
 * so every test above stays green.
 *
 * `explanation/concurrency-and-locking.md`, "Choosing the assertion": when
 * something backs the invariant, the discriminator is not the outcome but
 * *how* it was reached. So each layer gets a test that observes it directly
 * rather than through its effect.
 */

it('does not even load an order that is not awaiting payment', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);

    // A COD order at New with an ancient history row: the loop guard would
    // skip it anyway, so the only way to prove the *query* excludes it is
    // to watch what the query asks for.
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $order->update(['payment_method' => PaymentMethod::CashOnDelivery, 'anonymized_at' => null]);
    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => null,
        'new_status' => OrderStatus::New,
        'created_at' => now()->subDays(30),
    ]);

    $candidateSql = null;
    $candidateBindings = [];
    DB::listen(function ($query) use (&$candidateSql, &$candidateBindings): void {
        if ($candidateSql === null && str_starts_with(mb_strtolower($query->sql), 'select * from `orders`')) {
            $candidateSql = $query->sql;
            $candidateBindings = $query->bindings;
        }
    });

    app(ExpireUnpaidOrders::class)->handle();

    // The candidate query itself has to carry the status predicate. Asserting
    // only that the order survived would pass with the filter deleted, since
    // the loop guard would skip it a moment later — which is exactly what
    // made the first version of this test unfalsifiable.
    expect($candidateSql)->not->toBeNull()
        ->and($candidateSql)->toContain('`status` = ?')
        ->and($candidateBindings)->toContain(OrderStatus::AwaitingPayment->value);
});

/*
 * The loop guard's own window, observed rather than inferred: an order that
 * *was* a legitimate candidate when the query ran, but was paid before the
 * transition. Simulated by paying it from inside a DB::listen hook that
 * fires after the candidate SELECT — the same interleaving a webhook
 * produces, without needing a second process.
 */
it('skips an order paid between the candidate query and the transition', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);
    $order = unpaidOrder(minutesAgo: 11);

    $paid = false;
    DB::listen(function ($query) use ($order, &$paid): void {
        if ($paid) {
            return;
        }

        if (str_contains(mb_strtolower($query->sql), 'select * from `orders`')) {
            $paid = true;
            // Straight to the column: TransitionOrderStatus would dispatch
            // events and write history, and this is standing in for another
            // process's already-committed write, not performing one.
            DB::table('orders')->where('id', $order->getKey())
                ->update(['status' => OrderStatus::Paid->value]);
        }
    });

    $cancelled = app(ExpireUnpaidOrders::class)->handle();

    // The query selected it; the guard refused it. Remove the guard and the
    // sweep cancels an order that has been paid for.
    expect($paid)->toBeTrue()
        ->and($cancelled)->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(reservedFor($order))->toBe(2);
});

it('refuses a nonsensical TTL rather than sweeping everything', function (): void {
    config(['orders.unpaid_ttl_minutes' => 0]);
    unpaidOrder(minutesAgo: 11);

    // A zero or negative TTL would mean "cancel every unpaid order the
    // instant it is created". Refused loudly rather than acted on.
    expect(fn () => app(ExpireUnpaidOrders::class)->handle())
        ->toThrow(RuntimeException::class);
});
