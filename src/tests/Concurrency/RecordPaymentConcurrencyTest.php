<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The double-spend shape on the payment side.
 *
 * `Order::payment()` is a HasOne, which is a *relationship* declaration and
 * not a constraint — `payments` has no UNIQUE(order_id), verified below. So
 * two simultaneous checkouts of one order both read "no payment yet" and both
 * insert, unless RecordPayment's lockForUpdate() on the orders row serialises
 * them. That lock is the entire defence, which is why deleting it has to turn
 * these red.
 *
 * The discriminator is the winner *count*, not the exception type
 * (PublishProductConcurrencyTest's shape): without the lock both processes
 * succeed, so "exactly one payment" is what distinguishes a working lock from
 * a broken one.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach (['payment_events', 'payments', 'order_items', 'orders'] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

it('has no unique index standing in for the lock', function (): void {
    // States the precondition the rest of the file depends on. If someone
    // later adds UNIQUE(order_id), these tests would still pass with the lock
    // deleted — and would be proving the index instead, silently.
    $indexes = collect(DB::select('SHOW INDEX FROM payments'))
        ->filter(fn (object $row): bool => (int) $row->Non_unique === 0)
        ->pluck('Column_name')
        ->all();

    expect($indexes)->not->toContain('order_id');
});

it('lets exactly one of two simultaneous checkouts open a payment', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::New,
        'total_amount' => '120.00',
    ]);

    $outputs = runRaceWorkers([
        ['action' => 'record-payment', 'ids' => [$order->getKey()]],
        ['action' => 'record-payment', 'ids' => [$order->getKey()]],
    ]);

    $payments = Payment::where('order_id', $order->getKey())->get();

    expect($payments)->toHaveCount(1, raceReport($outputs));

    // The loser must fail as a domain refusal, not a duplicate-key 500 — a
    // QueryException escaping here would reach a checkout page as a 500.
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'PaymentAlreadyRecordedException')))
        ->toHaveCount(1, raceReport($outputs));

    // And the surviving payment still carries the server-side total.
    expect((string) $payments->sole()->amount)->toBe('120.00', raceReport($outputs));
});

it('lets exactly one of two concurrent partial refunds that would together overshoot', function (): void {
    // Each refund fits on its own — 60 of 100 — but together they are 120.
    // The cap is read inside the payments lock, so the second must see the
    // first's write. Without the lock both read refunded_amount = 0.00, both
    // pass their own check, and the payment ends up over-refunded: money out
    // the door, which is the worst failure in this codebase.
    $order = Order::factory()->create(['total_amount' => '100.00']);

    $payment = Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
        'refunded_amount' => '0.00',
    ]);

    $outputs = runRaceWorkers([
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['60.00']],
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['60.00']],
    ]);

    $refunded = (string) $payment->refresh()->refunded_amount;

    expect($refunded)->toBe('60.00', raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'InvalidArgumentException')))
        ->toHaveCount(1, raceReport($outputs));
});

it('lets both concurrent partial refunds through when they fit together', function (): void {
    // The counterpart that stops the test above from passing for the wrong
    // reason: if the Action simply refused every concurrent refund, the
    // overshoot test would still be green. 30 + 30 of 100 must BOTH land,
    // and accumulate — which is also the self-transition case
    // PaymentStatus allows and TransitionOrderStatus's no-op would eat.
    $order = Order::factory()->create(['total_amount' => '100.00']);

    $payment = Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
        'refunded_amount' => '0.00',
    ]);

    $outputs = runRaceWorkers([
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['30.00']],
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['30.00']],
    ]);

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(2, raceReport($outputs));

    expect((string) $payment->refresh()->refunded_amount)->toBe('60.00', raceReport($outputs));
});
