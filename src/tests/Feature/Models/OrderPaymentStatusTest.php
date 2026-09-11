<?php

declare(strict_types=1);

use App\Actions\Payment\RecordPayment;
use App\Actions\Payment\TransitionPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `orders.payment_status` was a stored column until 2026-08-24: written once
 * by CreateOrder and never again, while payments.status moved independently.
 * An order whose payment was paid still reported `pending`, and that was the
 * value the panel displayed and filtered on.
 *
 * It is now derived through the payment relation. What these tests protect is
 * that it cannot go stale again — which a column reintroduced by a later
 * migration silently would.
 */

it('has no payment_status column to go stale', function (): void {
    // The structural half of the guarantee. If someone re-adds the column,
    // Eloquent prefers the real attribute over the accessor and every
    // assertion below starts passing for the wrong reason — so assert the
    // absence directly rather than only the derived values.
    expect(Schema::hasColumn('orders', 'payment_status'))->toBeFalse();
});

it('reports pending when no payment row exists yet', function (): void {
    // CreateOrder does not open a payment (write-rules/order.md known gap 5),
    // so "no payment recorded" and "not yet processed" are the same state
    // from an order's point of view.
    $order = Order::factory()->create();

    expect($order->payment_status)->toBe(PaymentStatus::Pending);
});

it('tracks the payment status instead of holding a stale copy', function (): void {
    $order = Order::factory()->create(['total_amount' => '100.00']);

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Stripe, null);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);

    app(TransitionPaymentStatus::class)->handle($payment, PaymentStatus::Paid, null);

    // The exact case that was broken: before this became derived, the order
    // still read `pending` here.
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('follows a refund too', function (): void {
    $order = Order::factory()->create(['total_amount' => '100.00']);

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Stripe, null);
    app(TransitionPaymentStatus::class)->handle($payment, PaymentStatus::Paid, null);
    app(TransitionPaymentStatus::class)
        ->handle($payment->refresh(), PaymentStatus::PartiallyRefunded, null, '25.00');

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('reads one query for a list rather than one per order', function (): void {
    // The cost of deriving it. OrdersTable eager-loads `payment` for exactly
    // this reason; without that, a 50-row page is 50 extra queries. This
    // pins the eager-loaded path rather than the panel itself.
    Order::factory()->count(5)->create();

    DB::enableQueryLog();

    $orders = Order::query()->with('payment')->get();
    $orders->each(fn (Order $o) => $o->payment_status);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One for the orders, one for the payments. Not one per order.
    expect($queries)->toBe(2);
});
