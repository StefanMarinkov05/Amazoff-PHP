<?php

declare(strict_types=1);

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Mail\OrderPlaced;
use Illuminate\Support\Facades\Mail;

/*
 * ADR-0022 decision 5 — a card order's CRD Art. 8(7) confirmation goes out
 * when the payment arrives, not when the order is placed.
 *
 * Deliberately **no `Event::fake()` anywhere in this file.** Faking events
 * would suppress the very listener under test and every assertion here would
 * pass against a listener that was never invoked. These go through the real
 * TransitionOrderStatus so that the thing being proven includes Laravel
 * actually discovering `app/Listeners/` — this is the first listener in the
 * codebase, so that discovery has never been exercised before and is not
 * safe to assume.
 */

it('queues the confirmation when a card order reaches Paid', function (): void {
    Mail::fake();

    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::AwaitingPayment, 2);
    $order->update([
        'payment_method' => PaymentMethod::Stripe,
        'email' => 'card@example.test',
        'anonymized_at' => null,
    ]);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid, null);

    Mail::assertQueued(
        OrderPlaced::class,
        fn (OrderPlaced $mail): bool => $mail->hasTo('card@example.test')
            && $mail->order->is($order),
    );
});

it('does not queue anything for a transition that is not Paid', function (): void {
    Mail::fake();

    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::AwaitingPayment, 2);
    $order->update(['payment_method' => PaymentMethod::Stripe, 'anonymized_at' => null]);

    // Cancelling an abandoned order must not congratulate the customer on
    // their purchase — the abandonment bug in its other form.
    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, null);

    Mail::assertNotQueued(OrderPlaced::class);
});

/*
 * COD never reaches Paid through a payment webhook — it has no payment step
 * — but the listener guards on the method explicitly rather than relying on
 * the status graph, so the guard gets its own test. Staff marking a COD
 * order paid must not send a second confirmation on top of the one
 * CheckoutPage already sent at placement.
 */
it('does not queue a second confirmation for a cash-on-delivery order marked Paid', function (): void {
    Mail::fake();

    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::AwaitingPayment, 2);
    $order->update(['payment_method' => PaymentMethod::CashOnDelivery, 'anonymized_at' => null]);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid, null);

    Mail::assertNotQueued(OrderPlaced::class);
});

it('sends exactly one confirmation even though Paid is reachable only once', function (): void {
    Mail::fake();

    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::AwaitingPayment, 2);
    $order->update(['payment_method' => PaymentMethod::Stripe, 'anonymized_at' => null]);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid, null);
    // A redelivered webhook re-running the same transition is a no-op
    // ($from === $to), so no second event and no second email.
    app(TransitionOrderStatus::class)->handle($order->fresh(), OrderStatus::Paid, null);

    Mail::assertQueuedCount(1);
});
