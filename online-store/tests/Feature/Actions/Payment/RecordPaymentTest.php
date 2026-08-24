<?php

declare(strict_types=1);

use App\Actions\Payment\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentAlreadyRecordedException;
use App\Models\Order;
use App\Models\Payment;

/*
 * RecordPayment opens the payment row an order pays through. Two things here
 * are ours rather than the framework's: the amount is read off the order and
 * can never be supplied by a caller (§28, §37 criterion 8), and a second
 * payment for one order is refused rather than written.
 *
 * `Order::payment()` is a HasOne, but nothing in the schema stops two rows -
 * the refusal is the Action's, which is why it is worth asserting.
 */

it('opens a pending payment carrying the order total', function (): void {
    $order = Order::factory()->create(['total_amount' => '249.90']);

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::Stripe, null);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->method)->toBe(PaymentMethod::Stripe)
        ->and((string) $payment->amount)->toBe('249.90')
        ->and((string) $payment->refunded_amount)->toBe('0.00')
        ->and($payment->order_id)->toBe($order->getKey());
});

it('opens cash on delivery at pending too', function (): void {
    // Both methods start in the same place; what differs is what moves them
    // next - a webhook versus courier remittance - not where they begin.
    $order = Order::factory()->create(['total_amount' => '80.00']);

    $payment = app(RecordPayment::class)->handle($order, PaymentMethod::CashOnDelivery, null);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->method)->toBe(PaymentMethod::CashOnDelivery);
});

it('takes the amount from the order rather than from any caller', function (): void {
    // The Action has no amount parameter at all, which is the point: §28 puts
    // the total on the server, and an Action accepting one would be the single
    // place that rule could be bypassed silently. Two orders, two totals, no
    // caller input - the payments must differ.
    $cheap = Order::factory()->create(['total_amount' => '10.00']);
    $dear = Order::factory()->create(['total_amount' => '999.99']);

    $cheapPayment = app(RecordPayment::class)->handle($cheap, PaymentMethod::Stripe, null);
    $dearPayment = app(RecordPayment::class)->handle($dear, PaymentMethod::Stripe, null);

    expect((string) $cheapPayment->amount)->toBe('10.00')
        ->and((string) $dearPayment->amount)->toBe('999.99');
});

it('refuses a second payment for the same order', function (): void {
    $order = Order::factory()->create(['total_amount' => '50.00']);

    app(RecordPayment::class)->handle($order, PaymentMethod::Stripe, null);

    expect(fn () => app(RecordPayment::class)->handle($order, PaymentMethod::Stripe, null))
        ->toThrow(PaymentAlreadyRecordedException::class);

    // The refusal must leave the first payment alone, not roll it back.
    expect(Payment::where('order_id', $order->getKey())->count())->toBe(1);
});
