<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Support\Money;

/*
 * §37 criterion 11 — "duplicate Stripe events are not processed twice" —
 * under real concurrency.
 *
 * A single-process test can show that a *sequential* re-delivery is ignored,
 * and `StripePaymentTest` does. It cannot show what happens when two
 * deliveries of the same event are in flight at once, because one process
 * cannot hold two uncommitted transactions against the same row. That is
 * exactly the case the guard exists for: Stripe retries on any non-2xx, and
 * a slow first delivery plus an impatient retry puts two identical events on
 * the wire simultaneously.
 *
 * Two real processes, per `explanation/concurrency-and-locking.md`. The
 * signature is not in play here — this races the idempotency guard, which is
 * `payment_events.stripe_event_id`'s UNIQUE index, not the middleware.
 *
 * These commit their fixtures rather than using RefreshDatabase: a second
 * connection cannot see an uncommitted row, and a test for row contention
 * that rolled back would deadlock on its own write. tests/Pest.php says so.
 */

afterEach(function (): void {
    PaymentEvent::query()->delete();
    Payment::query()->delete();
    Order::query()->delete();
});

function racedStripePayment(string $intentId): Payment
{
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Stripe]);

    return Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Pending,
        'currency' => 'EUR',
        'amount' => '100.00',
        'refunded_amount' => '0.00',
        'stripe_payment_intent_id' => $intentId,
        'paid_at' => null,
    ]);
}

it('records one event and pays once when the same webhook arrives twice at once', function (): void {
    $intentId = 'pi_race_'.bin2hex(random_bytes(6));
    $eventId = 'evt_race_'.bin2hex(random_bytes(6));

    $payment = racedStripePayment($intentId);

    $outputs = runRaceWorkers([
        ['action' => 'handle-stripe-webhook', 'args' => [$eventId, $intentId]],
        ['action' => 'handle-stripe-webhook', 'args' => [$eventId, $intentId]],
    ]);

    // Both workers succeed: the loser catches the unique violation and
    // treats it as "already processed", which is the intended behaviour —
    // a duplicate is not an error to report back to Stripe.
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK'))->count())
        ->toBe(2, 'Both deliveries should be acknowledged.'.raceReport($outputs));

    // The invariant: exactly one event row, and the payment paid once.
    expect(PaymentEvent::where('stripe_event_id', $eventId)->count())
        ->toBe(1, 'The same Stripe event must be recorded exactly once.'.raceReport($outputs));

    expect($payment->fresh()->status)
        ->toBe(PaymentStatus::Paid, 'The payment should have been paid.'.raceReport($outputs));
});

it('pays once when two different events both say the payment succeeded', function (): void {
    $intentId = 'pi_two_'.bin2hex(random_bytes(6));

    $payment = racedStripePayment($intentId);

    // Distinct event ids, so the UNIQUE index does *not* catch this — both
    // rows are legitimate. What stops a double-apply here is the payments
    // row lock plus the transition matrix: the loser re-reads the locked row,
    // finds it already Paid, and Paid => Paid is not a legal move.
    $outputs = runRaceWorkers([
        ['action' => 'handle-stripe-webhook', 'args' => ['evt_a_'.bin2hex(random_bytes(4)), $intentId]],
        ['action' => 'handle-stripe-webhook', 'args' => ['evt_b_'.bin2hex(random_bytes(4)), $intentId]],
    ]);

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK'))->count())
        ->toBe(2, 'Neither delivery should fail.'.raceReport($outputs));

    // Two event rows — both events really happened and both are recorded...
    expect(PaymentEvent::where('payment_id', $payment->getKey())->count())
        ->toBe(2, 'Both distinct events should be recorded.'.raceReport($outputs));

    // ...but the payment moved to Paid once and has one paid_at.
    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Paid, raceReport($outputs))
        ->and($fresh->paid_at)->not->toBeNull();

    // Exactly one of the two actually applied; the other recorded a
    // no-op, because Paid cannot transition to Paid.
    $applied = PaymentEvent::where('payment_id', $payment->getKey())
        ->where('status_after', PaymentStatus::Paid->value)
        ->whereNull('note')
        ->count();

    expect($applied)->toBe(1, 'Exactly one event should have applied the transition.'.raceReport($outputs));
});

it('never lets a panel refund and a webhook refund together exceed the payment', function (): void {
    $intentId = 'pi_ref_'.bin2hex(random_bytes(6));

    $payment = racedStripePayment($intentId);
    $payment->update(['status' => PaymentStatus::Paid, 'amount' => '100.00']);

    /*
     * The realistic double-count: an administrator refunds 60 through the
     * panel at the same moment Stripe delivers charge.refunded reporting a
     * cumulative 60 for that same refund.
     *
     * Whichever order they land in, the total must be 60 — not 120. The two
     * sides use different arithmetic (the panel passes a delta, the webhook
     * computes one against what it finds), and the payments lock is what
     * makes the second one read the first one's write.
     */
    $outputs = runRaceWorkers([
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['60.00']],
        ['action' => 'webhook-refund', 'args' => ['evt_ref_'.bin2hex(random_bytes(4)), $intentId, '6000']],
    ]);

    $fresh = $payment->fresh();

    expect((string) $fresh->refunded_amount)
        ->toBe('60.00', 'The two refunds describe the same 60, not 120.'.raceReport($outputs));

    // And never beyond the payment, whatever happened.
    expect(Money::of((string) $fresh->refunded_amount)->isGreaterThan(Money::of((string) $fresh->amount)))
        ->toBeFalse('Refunded more than was taken.'.raceReport($outputs));
});

it('refuses the loser when two panel refunds would together overshoot', function (): void {
    $intentId = 'pi_over_'.bin2hex(random_bytes(6));

    $payment = racedStripePayment($intentId);
    $payment->update(['status' => PaymentStatus::Paid, 'amount' => '100.00']);

    // 60 + 60 = 120 against a 100.00 payment. Each fits alone; together they
    // do not. The cap is checked inside the payments lock precisely so the
    // second re-reads the first one's write rather than an unlocked snapshot.
    $outputs = runRaceWorkers([
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['60.00']],
        ['action' => 'partial-refund', 'ids' => [$payment->getKey()], 'args' => ['60.00']],
    ]);

    $succeeded = $outputs->filter(fn (string $o): bool => str_contains($o, 'OK'))->count();

    expect($succeeded)->toBe(1, 'Exactly one refund should survive.'.raceReport($outputs));

    expect((string) $payment->fresh()->refunded_amount)
        ->toBe('60.00', 'Only one 60.00 refund should have landed.'.raceReport($outputs));
});
