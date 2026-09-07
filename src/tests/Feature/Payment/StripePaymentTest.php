<?php

declare(strict_types=1);

use App\Actions\Payment\CreateStripeIntent;
use App\Actions\Payment\HandleStripeWebhookEvent;
use App\Actions\Payment\RefundPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\RefundNotAllowedException;
use App\Exceptions\StripeIntentNotAllowedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Stripe\Event as StripeEvent;
use Stripe\StripeClient;

/*
 * §37 criteria 9, 10, and 11 at the Action layer. The HTTP half — signature
 * verification, CSRF exemption, replay — is StripeWebhookSecurityTest.
 *
 * Stripe itself is faked. Not to avoid the network for its own sake, but
 * because what is under test here is *this application's* arithmetic and
 * state machine: that the amount handed to Stripe is the one on the payment
 * row, that a duplicate event moves nothing, that a partial refund
 * accumulates rather than double-counts. A real API call would prove Stripe
 * works, which is not ours.
 */

function fakeStripe(array $services): void
{
    // getService(), not __get(): StripeClient::__get() delegates to it, so
    // mocking only __get() leaves the real delegation running.
    $client = Mockery::mock(StripeClient::class);

    foreach ($services as $name => $service) {
        $client->shouldReceive('getService')->with($name)->andReturn($service);
    }

    app()->instance(StripeClient::class, $client);
}

function stripePayment(array $overrides = []): Payment
{
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Stripe]);

    return Payment::factory()->create(array_merge([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Pending,
        'currency' => 'EUR',
        'amount' => '100.00',
        'refunded_amount' => '0.00',
        'stripe_payment_intent_id' => null,
        'paid_at' => null,
    ], $overrides));
}

function stripeEvent(string $type, array $object, string $id = 'evt_1'): StripeEvent
{
    return StripeEvent::constructFrom([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ]);
}

/*
 * ── CreateStripeIntent (§37 #9) ─────────────────────────────────────────
 */

it('sends the amount from the payment row, in minor units, and stores the intent id', function (): void {
    $payment = stripePayment(['amount' => '189.90']);

    $captured = null;
    $intents = Mockery::mock();
    $intents->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (array $params, array $options) use (&$captured) {
            $captured = ['params' => $params, 'options' => $options];

            return (object) ['id' => 'pi_created_1'];
        });

    fakeStripe(['paymentIntents' => $intents]);

    app(CreateStripeIntent::class)->handle($payment);

    // 189.90 EUR is 18990 cents — an integer, never a float, and derived
    // from the row rather than from anything a caller passed.
    expect($captured['params']['amount'])->toBe(18990)
        ->and($captured['params']['amount'])->toBeInt()
        ->and($captured['params']['currency'])->toBe('eur')
        // Stripe's own idempotency guard, independent of the row lock.
        ->and($captured['options']['idempotency_key'])->toBe('payment-intent-'.$payment->getKey())
        ->and($payment->fresh()->stripe_payment_intent_id)->toBe('pi_created_1');
});

it('does not create a second intent for a payment that already has one', function (): void {
    $payment = stripePayment(['stripe_payment_intent_id' => 'pi_existing']);

    $intents = Mockery::mock();
    // The assertion: create() is never reached.
    $intents->shouldNotReceive('create');

    fakeStripe(['paymentIntents' => $intents]);

    $result = app(CreateStripeIntent::class)->handle($payment);

    expect($result->stripe_payment_intent_id)->toBe('pi_existing');
});

it('refuses an intent for a cash-on-delivery payment', function (): void {
    $payment = stripePayment(['method' => PaymentMethod::CashOnDelivery]);

    fakeStripe(['paymentIntents' => Mockery::mock()]);

    expect(fn () => app(CreateStripeIntent::class)->handle($payment))
        ->toThrow(StripeIntentNotAllowedException::class);
});

it('refuses an intent for a payment that is already paid', function (): void {
    $payment = stripePayment(['status' => PaymentStatus::Paid]);

    fakeStripe(['paymentIntents' => Mockery::mock()]);

    // Creating an intent here would invite charging for money already taken.
    expect(fn () => app(CreateStripeIntent::class)->handle($payment))
        ->toThrow(StripeIntentNotAllowedException::class);
});

/*
 * ── HandleStripeWebhookEvent (§37 #10) ──────────────────────────────────
 */

it('marks a payment paid on payment_intent.succeeded', function (): void {
    $payment = stripePayment(['stripe_payment_intent_id' => 'pi_ok']);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', ['id' => 'pi_ok', 'status' => 'succeeded', 'amount_received' => 10000, 'currency' => 'eur']),
    );

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Paid)
        ->and($fresh->paid_at)->not->toBeNull()
        ->and(PaymentEvent::where('payment_id', $payment->getKey())->count())->toBe(1);
});

it('marks a payment failed on payment_intent.payment_failed', function (): void {
    $payment = stripePayment(['stripe_payment_intent_id' => 'pi_bad']);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.payment_failed', ['id' => 'pi_bad', 'status' => 'requires_payment_method']),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
});

it('ignores an event for an intent it has never seen, without throwing', function (): void {
    // Another environment sharing the endpoint, or a test-mode event.
    // Throwing would make Stripe retry it for days.
    $result = app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', ['id' => 'pi_unknown']),
    );

    expect($result)->toBeNull()
        ->and(PaymentEvent::count())->toBe(0);
});

it('records but does not apply an out-of-order event implying an illegal move', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_late',
        'status' => PaymentStatus::Paid,
    ]);

    // Stripe does not guarantee ordering: `processing` can land after
    // `succeeded`. Paid => Processing is not in the matrix.
    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.processing', ['id' => 'pi_late']),
    );

    $event = PaymentEvent::where('payment_id', $payment->getKey())->first();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($event)->not->toBeNull()
        ->and($event->note)->toContain('Not applied');
});

it('acknowledges an event type it does not act on, without moving the payment', function (): void {
    $payment = stripePayment(['stripe_payment_intent_id' => 'pi_noise']);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.created', ['id' => 'pi_noise']),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(PaymentEvent::where('payment_id', $payment->getKey())->count())->toBe(1);
});

/*
 * ── Refunds through the webhook: the cumulative-versus-delta trap ───────
 *
 * Stripe reports `amount_refunded` as the running total on the charge, while
 * TransitionPaymentStatus *accumulates* what it is given. Passing Stripe's
 * figure straight through double-counts every refund after the first — the
 * first draft of this Action did exactly that, which is what these pin.
 */

it('applies a partial refund from charge.refunded at the right amount', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_refund',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.refunded', [
            'id' => 'ch_1',
            'payment_intent' => 'pi_refund',
            'amount_refunded' => 2500,
        ], 'evt_refund_1'),
    );

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and((string) $fresh->refunded_amount)->toBe('25.00');
});

it('does not double-count when a second partial refund reports a cumulative total', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_two',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    $handler = app(HandleStripeWebhookEvent::class);

    // 25 refunded.
    $handler->handle(stripeEvent('charge.refunded', [
        'id' => 'ch_1', 'payment_intent' => 'pi_two', 'amount_refunded' => 2500,
    ], 'evt_a'));

    // Another 15 refunded — Stripe reports the running total, 40, not 15.
    $handler->handle(stripeEvent('charge.refunded', [
        'id' => 'ch_1', 'payment_intent' => 'pi_two', 'amount_refunded' => 4000,
    ], 'evt_b'));

    // 40.00, not 65.00 (25 + 40) which is what passing the cumulative figure
    // straight into an accumulating Action produces.
    expect((string) $payment->fresh()->refunded_amount)->toBe('40.00')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('marks a payment fully refunded when the cumulative total reaches the amount', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_full',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.refunded', [
            'id' => 'ch_1', 'payment_intent' => 'pi_full', 'amount_refunded' => 10000,
        ], 'evt_full'),
    );

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Refunded)
        ->and((string) $fresh->refunded_amount)->toBe('100.00');
});

/*
 * ── Idempotency (§37 #11) at the Action layer ───────────────────────────
 */

it('applies the same event id only once', function (): void {
    $payment = stripePayment(['stripe_payment_intent_id' => 'pi_dupe']);
    $event = stripeEvent('payment_intent.succeeded', ['id' => 'pi_dupe', 'amount_received' => 10000, 'currency' => 'eur'], 'evt_same');

    $handler = app(HandleStripeWebhookEvent::class);

    expect($handler->handle($event))->not->toBeNull()
        // Second delivery: the UNIQUE on stripe_event_id catches it.
        ->and($handler->handle($event))->toBeNull()
        ->and(PaymentEvent::where('stripe_event_id', 'evt_same')->count())->toBe(1);
});

/*
 * ── RefundPayment ───────────────────────────────────────────────────────
 */

it('refunds in full through Stripe and marks the payment refunded', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_r',
        'status' => PaymentStatus::Paid,
        'amount' => '80.00',
    ]);

    $captured = null;
    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')->once()->andReturnUsing(function (array $params) use (&$captured) {
        $captured = $params;

        return (object) ['id' => 're_1'];
    });

    fakeStripe(['refunds' => $refunds]);

    // Null actor: the system refunding on its own behalf, which skips
    // authorization by design (ADR-0007). The permission path is asserted
    // separately, below.
    $result = app(RefundPayment::class)->handle($payment, null, null);

    expect($captured['amount'])->toBe(8000)
        ->and($captured['payment_intent'])->toBe('pi_r')
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and((string) $result->refunded_amount)->toBe('80.00');
});

it('refuses a refund from an actor holding no refund_payment permission', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_authz',
        'status' => PaymentStatus::Paid,
        'amount' => '50.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldNotReceive('create');
    fakeStripe(['refunds' => $refunds]);

    // Refunding money is not editing a row — permissions.md gives
    // refund_payment to the administrator only, and this user holds nothing.
    $nobody = User::factory()->create();

    expect(fn () => app(RefundPayment::class)->handle($payment, null, $nobody))
        ->toThrow(AuthorizationException::class);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('refuses to refund more than remains unrefunded', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_over',
        'status' => PaymentStatus::PartiallyRefunded,
        'amount' => '100.00',
        'refunded_amount' => '90.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldNotReceive('create');
    fakeStripe(['refunds' => $refunds]);

    // Checked before Stripe is called, so an over-refund never leaves the
    // application — and checked inside the lock, so two concurrent refunds
    // cannot both pass.
    expect(fn () => app(RefundPayment::class)->handle($payment, '20.00', null))
        ->toThrow(RefundNotAllowedException::class);

    expect((string) $payment->fresh()->refunded_amount)->toBe('90.00');
});

it('refuses to refund a payment that was never paid', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_unpaid',
        'status' => PaymentStatus::Pending,
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldNotReceive('create');
    fakeStripe(['refunds' => $refunds]);

    expect(fn () => app(RefundPayment::class)->handle($payment, null, null))
        ->toThrow(RefundNotAllowedException::class);
});

it('refuses to refund a cash-on-delivery payment through Stripe', function (): void {
    $payment = stripePayment([
        'method' => PaymentMethod::CashOnDelivery,
        'status' => PaymentStatus::Paid,
        'stripe_payment_intent_id' => null,
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldNotReceive('create');
    fakeStripe(['refunds' => $refunds]);

    // COD has no Stripe charge to reverse; refunding one is a cash process
    // this application does not model.
    expect(fn () => app(RefundPayment::class)->handle($payment, null, null))
        ->toThrow(RefundNotAllowedException::class);
});

/*
 * ── Refund edge cases ───────────────────────────────────────────────────
 */

it('marks a payment fully refunded when a partial refund takes exactly the remainder', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_exact',
        'status' => PaymentStatus::PartiallyRefunded,
        'amount' => '100.00',
        'refunded_amount' => '70.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')->once()->andReturn((object) ['id' => 're_x']);
    fakeStripe(['refunds' => $refunds]);

    // Full versus partial is decided by what remains, not by whether the
    // caller named an amount: refunding exactly the remainder is a full
    // refund however it was asked for.
    $result = app(RefundPayment::class)->handle($payment, '30.00', null);

    expect($result->status)->toBe(PaymentStatus::Refunded)
        ->and((string) $result->refunded_amount)->toBe('100.00');
});

it('accumulates successive partial refunds rather than replacing them', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_acc',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')->twice()->andReturn((object) ['id' => 're_y']);
    fakeStripe(['refunds' => $refunds]);

    $action = app(RefundPayment::class);
    $action->handle($payment, '20.00', null);
    $result = $action->handle($payment->fresh(), '30.00', null);

    expect((string) $result->refunded_amount)->toBe('50.00')
        ->and($result->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('sends a different idempotency key for a second refund of the same size', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_keys',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    $keys = [];
    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')->twice()->andReturnUsing(function (array $p, array $o) use (&$keys) {
        $keys[] = $o['idempotency_key'];

        return (object) ['id' => 're_z'];
    });
    fakeStripe(['refunds' => $refunds]);

    $action = app(RefundPayment::class);
    $action->handle($payment, '10.00', null);
    $action->handle($payment->fresh(), '10.00', null);

    // Keyed on the running total as well as the payment, so a *retry* of one
    // refund is idempotent while two genuine refunds of the same size both
    // go through. Same key for both would silently drop the second.
    expect($keys[0])->not->toBe($keys[1]);
});

it('refuses a zero or negative refund', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_zero',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldNotReceive('create');
    fakeStripe(['refunds' => $refunds]);

    $action = app(RefundPayment::class);

    expect(fn () => $action->handle($payment, '0.00', null))->toThrow(RefundNotAllowedException::class);
    expect(fn () => $action->handle($payment, '-5.00', null))->toThrow(RefundNotAllowedException::class);
});

it('refuses a refund on an already fully refunded payment', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_done',
        'status' => PaymentStatus::Refunded,
        'amount' => '100.00',
        'refunded_amount' => '100.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldNotReceive('create');
    fakeStripe(['refunds' => $refunds]);

    // Refunded is terminal in PaymentStatus's matrix, and there is nothing
    // left to return either way.
    expect(fn () => app(RefundPayment::class)->handle($payment, null, null))
        ->toThrow(RefundNotAllowedException::class);
});

it('does not double-count when the webhook echoes an admin-initiated refund', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_echo',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')->once()->andReturn((object) ['id' => 're_echo']);
    fakeStripe(['refunds' => $refunds]);

    // The admin refunds 25 through the panel...
    app(RefundPayment::class)->handle($payment, '25.00', null);

    // ...and Stripe then delivers charge.refunded for the same 25, because
    // refunding through the API fires the webhook too. The delta comes out
    // zero, so the event is recorded and nothing moves.
    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.refunded', [
            'id' => 'ch_echo', 'payment_intent' => 'pi_echo', 'amount_refunded' => 2500,
        ], 'evt_echo'),
    );

    $fresh = $payment->fresh();

    expect((string) $fresh->refunded_amount)->toBe('25.00')
        ->and($fresh->status)->toBe(PaymentStatus::PartiallyRefunded);

    $event = PaymentEvent::where('stripe_event_id', 'evt_echo')->first();
    expect($event->note)->toContain('already been recorded');
});

it('refuses to mark a payment paid for an amount that does not match the row', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_short',
        'amount' => '100.00',
    ]);

    // Stripe says the charge succeeded — for 1.00, not 100.00. Marking this
    // Paid would ship goods for a hundredth of their price.
    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_short', 'status' => 'succeeded', 'amount_received' => 100, 'currency' => 'eur',
        ], 'evt_short'),
    );

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->paid_at)->toBeNull();

    // Recorded, so an operator can see it happened and why.
    $event = PaymentEvent::where('stripe_event_id', 'evt_short')->first();

    expect($event)->not->toBeNull()
        ->and($event->note)->toContain('does not match the expected 10000');
});

it('accepts a paid event whose amount matches exactly', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_exact_amt',
        'amount' => '49.99',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_exact_amt', 'status' => 'succeeded', 'amount_received' => 4999, 'currency' => 'eur',
        ], 'evt_exact_amt'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('refuses to mark a payment paid when the currency does not match', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_wrong_currency',
        'amount' => '100.00',
        'currency' => 'EUR',
    ]);

    // Amount is right in minor units, currency is not — the exact shape a
    // multi-currency mix-up produces.
    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_wrong_currency', 'status' => 'succeeded', 'amount_received' => 10000, 'currency' => 'usd',
        ], 'evt_wrong_currency'),
    );

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->paid_at)->toBeNull();

    // The received currency is var_export'd (it can be null), the expected
    // one is not — it always comes from our own row.
    $event = PaymentEvent::where('stripe_event_id', 'evt_wrong_currency')->first();
    expect($event->note)->toContain("'usd'")->toContain('eur');
});

it('does not fall back to the requested amount when Stripe omits amount_received', function (): void {
    // The requested amount on the intent — CreateStripeIntent's own figure —
    // equals what the payment expects by construction, since it is the same
    // Action that set both. A fallback to `amount` here would make the guard
    // compare that figure against itself and pass unconditionally, which is
    // exactly what a first draft of HandleStripeWebhookEvent did before this
    // test caught it.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_no_received',
        'amount' => '100.00',
        'currency' => 'EUR',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_no_received',
            'status' => 'succeeded',
            'amount' => 10000, // requested — present, and must NOT be trusted
            'currency' => 'eur',
            // amount_received deliberately absent.
        ], 'evt_no_received'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

/*
 * ── Shapes verified against the real Stripe test API ────────────────────
 *
 * These pin field-level assumptions that were checked against live
 * test-mode objects in the project's own Stripe sandbox (acct_1UAbac…,
 * livemode false) rather than only against the SDK's generated stubs. The
 * real objects are recorded in `docs/reference/testing/stripe-testing.md`.
 *
 * They are cheap and they guard a specific failure: a Stripe API version
 * bump that renames or restructures one of these fields would otherwise
 * change what the webhook reads with no error anywhere — the exact reason
 * the API version is pinned in AppServiceProvider.
 */

it('reads the same PaymentIntent fields the real API returns', function (): void {
    // Field-for-field the shape of a real succeeded intent, trimmed to what
    // HandleStripeWebhookEvent actually touches.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_realshape',
        'amount' => '20.00',
        'currency' => 'EUR',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_realshape',
            'object' => 'payment_intent',
            'amount' => 2000,
            'amount_received' => 2000,
            'currency' => 'eur',
            'status' => 'succeeded',
            'last_payment_error' => null,
            'latest_charge' => 'ch_realshape',
        ], 'evt_realshape'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('classifies a partial refund by amount, not by the charge refunded flag', function (): void {
    /*
     * A real charge object carries BOTH `amount_refunded` (int, cumulative)
     * and `refunded` (bool). Verified on a live test charge: `refunded` is
     * false while a charge is only *partially* refunded, so trusting the
     * boolean would mark a partial refund as "not refunded" and leave the
     * payment at Paid. statusFor() compares amounts instead.
     */
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_flag',
        'status' => PaymentStatus::Paid,
        'amount' => '20.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.refunded', [
            'id' => 'ch_flag',
            'object' => 'charge',
            'payment_intent' => 'pi_flag',
            'amount' => 2000,
            'amount_refunded' => 500,
            // Stripe's own flag says "not fully refunded". Deliberately
            // contradicts the amount, and the amount is what wins.
            'refunded' => false,
            'currency' => 'eur',
        ], 'evt_flag'),
    );

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and((string) $fresh->refunded_amount)->toBe('5.00');
});

it('stores no cardholder or card data from a real charge payload', function (): void {
    /*
     * A real charge carries billing_details (name, email, full address),
     * payment_method_details.card.last4, and a receipt_url. §33's
     * data-minimisation point applies to a debugging column too, and
     * payment_events is readable from the panel.
     */
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_pii',
        'status' => PaymentStatus::Paid,
        'amount' => '20.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.refunded', [
            'id' => 'ch_pii',
            'payment_intent' => 'pi_pii',
            'amount_refunded' => 2000,
            'currency' => 'eur',
            'billing_details' => ['name' => 'Jenny Rosen', 'email' => 'jenny@example.test'],
            'payment_method_details' => ['card' => ['last4' => '4242', 'brand' => 'visa']],
            'receipt_url' => 'https://pay.stripe.com/receipts/secret-token',
        ], 'evt_pii'),
    );

    $stored = json_encode(PaymentEvent::where('stripe_event_id', 'evt_pii')->first()->payload);

    expect($stored)->not->toContain('Jenny Rosen')
        ->not->toContain('jenny@example.test')
        ->not->toContain('4242')
        ->not->toContain('receipts/secret-token');
});

/*
 * ── Disputes ────────────────────────────────────────────────────────────
 *
 * A customer can dispute a charge with their bank after the money moved.
 * Before this the event had nowhere to land: the payment stayed Paid and
 * the order shipped, with the only trace in Stripe's own dashboard.
 */

it('marks a paid payment disputed on charge.dispute.created', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.created', [
            'id' => 'dp_1',
            'object' => 'dispute',
            'charge' => 'ch_disp',
            'payment_intent' => 'pi_disp',
            'amount' => 10000,
            'currency' => 'eur',
            'status' => 'needs_response',
        ], 'evt_disp'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Disputed);
});

it('resolves the intent when Stripe expands payment_intent into an object', function (): void {
    /*
     * Stripe types Dispute::$payment_intent as null|PaymentIntent|string —
     * an id normally, an expanded object when expansion was requested.
     * Reading it as a string only returned null and silently ignored a real
     * dispute.
     */
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_expanded',
        'status' => PaymentStatus::Paid,
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.created', [
            'id' => 'dp_2',
            'charge' => 'ch_expanded',
            // The expanded shape, not a bare id.
            'payment_intent' => (object) ['id' => 'pi_expanded', 'object' => 'payment_intent'],
            'amount' => 10000,
            'currency' => 'eur',
        ], 'evt_expanded'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Disputed);
});

it('disputes a partially refunded payment too', function (): void {
    // Money still arrived, so it is still disputable.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp_partial',
        'status' => PaymentStatus::PartiallyRefunded,
        'amount' => '100.00',
        'refunded_amount' => '20.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.created', [
            'id' => 'dp_3', 'payment_intent' => 'pi_disp_partial', 'amount' => 8000, 'currency' => 'eur',
        ], 'evt_disp_partial'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Disputed);
});

it('records but does not apply a dispute against an unpaid payment', function (): void {
    // Pending => Disputed is not in the matrix: no money arrived, so there
    // is nothing to dispute. Out-of-order delivery, recorded not applied.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp_unpaid',
        'status' => PaymentStatus::Pending,
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.created', [
            'id' => 'dp_4', 'payment_intent' => 'pi_disp_unpaid', 'amount' => 10000, 'currency' => 'eur',
        ], 'evt_disp_unpaid'),
    );

    $event = PaymentEvent::where('stripe_event_id', 'evt_disp_unpaid')->first();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($event->note)->toContain('Not applied');
});

it('does not apply the paid-amount guard to a dispute', function (): void {
    // A dispute can be for less than the charge — Stripe says so explicitly:
    // "Usually the amount of the charge, but it can differ". The amount
    // guard exists to stop marking something Paid for the wrong sum, and
    // must not leak into an event that is not about being paid.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp_partial_amt',
        'status' => PaymentStatus::Paid,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.created', [
            'id' => 'dp_5',
            'payment_intent' => 'pi_disp_partial_amt',
            // Deliberately not the full charge amount.
            'amount' => 4000,
            'currency' => 'eur',
        ], 'evt_disp_partial_amt'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Disputed);
});

it('returns a disputed payment to Paid when the dispute is won', function (): void {
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp_won',
        'status' => PaymentStatus::Disputed,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.closed', [
            'id' => 'dp_won',
            'payment_intent' => 'pi_disp_won',
            'amount' => 10000,
            'currency' => 'eur',
            'status' => 'won',
        ], 'evt_disp_won'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('moves a disputed payment to Refunded when the dispute is lost', function (): void {
    // §37 note in PaymentStatus::allowedTransitions(): a dispute lost ends
    // as Refunded, not back at Disputed's origin, since the funds are taken
    // back from the merchant either way.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp_lost',
        'status' => PaymentStatus::Disputed,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.closed', [
            'id' => 'dp_lost',
            'payment_intent' => 'pi_disp_lost',
            'amount' => 10000,
            'currency' => 'eur',
            'status' => 'lost',
        ], 'evt_disp_lost'),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('acknowledges charge.dispute.closed without a decided outcome, without moving the payment', function (): void {
    // charge.dispute.closed also fires for warning_closed - an inquiry that
    // never became a formal dispute. Only won/lost are decisions this
    // application acts on; anything else is recorded and ignored, the same
    // as any other event type not thought through, per the class docblock.
    $payment = stripePayment([
        'stripe_payment_intent_id' => 'pi_disp_inquiry',
        'status' => PaymentStatus::Disputed,
        'amount' => '100.00',
    ]);

    app(HandleStripeWebhookEvent::class)->handle(
        stripeEvent('charge.dispute.closed', [
            'id' => 'dp_inquiry',
            'payment_intent' => 'pi_disp_inquiry',
            'amount' => 10000,
            'currency' => 'eur',
            'status' => 'warning_closed',
        ], 'evt_disp_inquiry'),
    );

    $event = PaymentEvent::where('stripe_event_id', 'evt_disp_inquiry')->first();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Disputed)
        ->and($event)->not->toBeNull()
        ->and($event->note)->toContain('Acknowledged');
});
