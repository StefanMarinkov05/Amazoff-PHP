<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GDPR Art. 17 erasure and its retention purge under concurrency (ADR-0019).
 * `EraseCustomer` locks the user row and its non-anonymised orders inside one
 * transaction; `PurgeAnonymisedOrders` locks each matched order. What these
 * prove is not a winner count — the database guarantees one final state — but
 * that the *loser* fails as a handled exception, and that no half-written
 * anonymisation or half-deleted order subtree is ever left behind.
 *
 * `write-rules/gdpr.md`, "One actor at a time", is the outcomes page these
 * back.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'payment_events', 'payments', 'return_items', 'returns',
        'order_status_histories', 'order_items', 'order_addresses', 'orders',
        'product_reviews', 'newsletter_subscribers', 'contact_messages',
        'wishlist_items', 'cart_items', 'carts', 'addresses', 'users',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

it('anonymises a customer\'s order exactly once when two erasures race', function (): void {
    $user = User::factory()->create();
    // OrderFactory fills anonymized_at by default; a live order has none.
    $order = Order::factory()->for($user)->create([
        'anonymized_at' => null,
        'email' => 'real@example.com',
        'first_name' => 'Real',
    ]);

    $outputs = runRaceWorkers([
        ['action' => 'erase-customer', 'ids' => [$user->getKey()]],
        ['action' => 'erase-customer', 'ids' => [$user->getKey()]],
    ]);

    // The user row is gone, once.
    expect(User::withTrashed()->find($user->getKey()))->toBeNull(raceReport($outputs));

    // The order is anonymised, and its identity columns were overwritten
    // exactly once — not re-overwritten with a second timestamp.
    $order->refresh();
    expect($order->exists)->toBeTrue()
        ->and($order->anonymized_at)->not->toBeNull()
        ->and($order->email)->toBe("erased-{$order->getKey()}@anonymized.invalid")
        ->and($order->first_name)->toBe('[erased]');

    // One worker did the work; the other found nothing to do and said so
    // cleanly — never a QueryException.
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(1, raceReport($outputs));
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'ModelNotFoundException')))
        ->toHaveCount(1, raceReport($outputs));
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'QueryException')))
        ->toHaveCount(0, raceReport($outputs));
});

it('keeps both effects when an erasure races an order-status transition', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create([
        'anonymized_at' => null,
        'status' => OrderStatus::Shipped,
        'email' => 'real@example.com',
    ]);
    // The Shipped history row so the transition has a legal `from`.
    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => OrderStatus::ReadyForShipment,
        'new_status' => OrderStatus::Shipped,
        'user_id' => null,
    ]);

    $outputs = runRaceWorkers([
        ['action' => 'erase-customer', 'ids' => [$user->getKey()]],
        ['action' => 'transition-order-status', 'ids' => [$order->getKey()], 'args' => ['delivered']],
    ]);

    $order->refresh();

    // Both writes landed: status is not identity, so anonymising the order
    // and advancing it are independent and both succeed whichever commits
    // first. Exactly one Delivered history row.
    expect($order->anonymized_at)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->email)->toBe("erased-{$order->getKey()}@anonymized.invalid")
        ->and(OrderStatusHistory::where('order_id', $order->getKey())->where('new_status', OrderStatus::Delivered)->count())
        ->toBe(1, raceReport($outputs));

    expect(User::withTrashed()->find($user->getKey()))->toBeNull(raceReport($outputs));

    // Both operations completed — the transition is not a race loser here,
    // it just serialises on the orders lock.
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(2, raceReport($outputs));
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'QueryException')))
        ->toHaveCount(0, raceReport($outputs));
});

it('deletes an anonymised order cleanly when a purge races a refund on it', function (): void {
    // An anonymised order past the retention cutoff, still carrying a paid
    // Stripe payment.
    $order = Order::factory()->create([
        'anonymized_at' => now()->subYears(12),
        'total_amount' => '100.00',
    ]);
    $payment = Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
        'refunded_amount' => '0.00',
    ]);

    // Jobs come back in order: [0] the purge, [1] the refund.
    $outputs = runRaceWorkers([
        ['action' => 'purge-anonymised-orders', 'ids' => []],
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['40.00']],
    ]);
    [$purge, $refund] = [$outputs[0], $outputs[1]];

    // The purge always runs, so the order and its whole subtree are gone
    // regardless of which side won.
    expect(Order::find($order->getKey()))->toBeNull(raceReport($outputs))
        ->and(Payment::find($payment->getKey()))->toBeNull(raceReport($outputs));

    // The purge succeeded. The refund either ran before the delete (OK) or
    // found the row gone (ModelNotFoundException) — both clean, never a
    // foreign-key QueryException or a partially deleted subtree.
    expect($purge)->toBe('OK', raceReport($outputs))
        ->and($refund === 'OK' || str_contains($refund, 'ModelNotFoundException'))
        ->toBeTrue(raceReport($outputs));
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'QueryException')))
        ->toHaveCount(0, raceReport($outputs));
});
