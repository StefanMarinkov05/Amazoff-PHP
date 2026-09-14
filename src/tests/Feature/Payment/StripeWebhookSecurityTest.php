<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Middleware\RestrictStripeWebhookIps;
use App\Http\Middleware\VerifyStripeWebhookSignature;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\Log;

/*
 * Adversarial tests against POST /stripe/webhook.
 *
 * This endpoint is the one place in the application that takes an
 * unauthenticated, CSRF-exempt request from the public internet and moves
 * money-bearing state as a result. CLAUDE.md states the rule it has to
 * satisfy — "CSRF-excluded **and** signature-verified. One without the other
 * is a free-products vulnerability" — and these are the tests that hold it
 * to that.
 *
 * Every case below is written from the attacker's side: what does someone
 * who can POST to this URL, and who does *not* hold the signing secret, have
 * to do to get an order marked paid? The answer must be "nothing works", and
 * each test names one specific attempt.
 *
 * The signing helper is deliberately a real HMAC rather than a mock. Mocking
 * Stripe's verifier would test that a mock returns what it was told to, and
 * prove nothing about whether this endpoint actually rejects a forged
 * request.
 */

const TEST_WEBHOOK_SECRET = 'whsec_test_secret_for_signature_verification';

beforeEach(function (): void {
    config()->set('services.stripe.webhook_secret', TEST_WEBHOOK_SECRET);
    config()->set('services.stripe.webhook_tolerance', 300);

    // Log assertions below need a spy rather than the real logger.
    Log::spy();
});

/**
 * A correctly signed request, exactly as Stripe would send one.
 *
 * `t=<unix>,v1=<hmac_sha256("<t>.<payload>", secret)>` — the scheme Stripe
 * documents. Built by hand so the tests exercise the real verifier.
 */
function stripeSignature(string $payload, ?int $timestamp = null, ?string $secret = null): string
{
    $timestamp ??= time();
    $secret ??= TEST_WEBHOOK_SECRET;

    $digest = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$digest}";
}

/**
 * A `payment_intent.succeeded` body for a given intent — the event an
 * attacker would most want to forge, since it is the one that means "paid".
 */
function succeededEventPayload(string $intentId, string $eventId = 'evt_test_1', int $amount = 10000): string
{
    return json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'id' => $intentId,
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => $amount,
                // What the webhook's amount guard actually reads. `amount`
                // is the requested figure and is deliberately not trusted —
                // see HandleStripeWebhookEvent.
                'amount_received' => $amount,
                'currency' => 'eur',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

/** A Stripe payment sitting at Pending, waiting on its webhook. */
function pendingStripePayment(string $intentId = 'pi_test_123', string $amount = '100.00'): Payment
{
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Stripe]);

    return Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Pending,
        'amount' => $amount,
        'refunded_amount' => '0.00',
        'stripe_payment_intent_id' => $intentId,
        'paid_at' => null,
    ]);
}

/*
 * ── The core attack: forge a payment ────────────────────────────────────
 */

it('refuses an unsigned request claiming a payment succeeded', function (): void {
    $payment = pendingStripePayment();

    $this->postJson('/stripe/webhook', json_decode(succeededEventPayload('pi_test_123'), true))
        ->assertStatus(400);

    // The whole point: the money state did not move.
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->paid_at)->toBeNull()
        ->and(PaymentEvent::count())->toBe(0);
});

it('refuses a request whose signature was made with the wrong secret', function (): void {
    $payment = pendingStripePayment();
    $payload = succeededEventPayload('pi_test_123');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, null, 'whsec_attacker_guess'), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('refuses a garbage signature header', function (): void {
    $payment = pendingStripePayment();
    $payload = succeededEventPayload('pi_test_123');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => 'not-a-signature', 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

/*
 * ── Tampering: a real signature, a changed body ─────────────────────────
 *
 * The interesting case, because it is the one a naive implementation gets
 * wrong: verify the signature, then act on a re-parsed body. The MAC covers
 * the bytes, so any edit invalidates it.
 */

it('refuses a body edited after signing, even by one digit of the amount', function (): void {
    $payment = pendingStripePayment(amount: '100.00');
    $original = succeededEventPayload('pi_test_123', amount: 10000);
    $signature = stripeSignature($original);

    // The attacker keeps the captured signature and inflates the amount.
    $tampered = str_replace('"amount":10000', '"amount":1', $original);

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $tampered,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('refuses a signature captured from a different intent, replayed against another payment', function (): void {
    $victim = pendingStripePayment('pi_victim');
    $attackerOwn = pendingStripePayment('pi_attacker');

    // A signature legitimately obtained for the attacker's own payment...
    $ownPayload = succeededEventPayload('pi_attacker', 'evt_own');
    $signature = stripeSignature($ownPayload);

    // ...reused against the victim's intent id.
    $swapped = str_replace('pi_attacker', 'pi_victim', $ownPayload);

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $swapped,
    )->assertStatus(400);

    expect($victim->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($attackerOwn->fresh()->status)->toBe(PaymentStatus::Pending);
});

/*
 * ── Replay ──────────────────────────────────────────────────────────────
 */

it('refuses a valid request replayed outside the tolerance window', function (): void {
    $payment = pendingStripePayment();
    $payload = succeededEventPayload('pi_test_123');

    // Captured yesterday, replayed today. The timestamp is inside the MAC,
    // so the attacker cannot refresh it without the secret.
    $signature = stripeSignature($payload, time() - 86_400);

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('applies a genuinely signed event exactly once, however many times it is replayed', function (): void {
    $payment = pendingStripePayment();
    $payload = succeededEventPayload('pi_test_123', 'evt_replay_me');
    $signature = stripeSignature($payload);

    $send = fn () => $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $send()->assertOk();
    $send()->assertOk();
    $send()->assertOk();

    // §37 #11. Within the tolerance window a captured request *can* be
    // resent, and the signature will still verify — so the signature is not
    // what makes replay harmless. The UNIQUE on payment_events.stripe_event_id
    // is.
    expect(PaymentEvent::where('stripe_event_id', 'evt_replay_me')->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

/*
 * ── Fail-closed ─────────────────────────────────────────────────────────
 */

it('refuses every webhook when the signing secret is not configured', function (): void {
    // The most likely real-world misconfiguration: a deploy with a blank
    // STRIPE_WEBHOOK_SECRET. It must fail closed, never fall through to the
    // handler with verification skipped.
    config()->set('services.stripe.webhook_secret', '');

    $payment = pendingStripePayment();
    $payload = succeededEventPayload('pi_test_123');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertStatus(500);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

/*
 * ── IP allow-list (App\Http\Middleware\RestrictStripeWebhookIps) ────────
 *
 * The second half of Stripe's recommended pairing, applied at the
 * application layer rather than at an edge this deploy does not control —
 * see the middleware's own docblock. Signature verification alone already
 * defeats every attack above; these cases are about this layer specifically:
 * does it actually narrow the source, and does it fail the right direction
 * when unconfigured.
 */

it('rejects a genuinely signed request from an IP outside the configured allow-list', function (): void {
    config()->set('services.stripe.webhook_allowed_ips', '203.0.113.5');

    $payment = pendingStripePayment('pi_bad_ip');
    $payload = succeededEventPayload('pi_bad_ip', 'evt_bad_ip');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.7',
        ],
        $payload,
    )->assertStatus(403);

    // The point: even a perfectly valid signature does not move money once
    // the request comes from an IP the allow-list does not name.
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(PaymentEvent::count())->toBe(0);
});

it('accepts a genuinely signed request from an IP inside the configured allow-list', function (): void {
    config()->set('services.stripe.webhook_allowed_ips', '198.51.100.0/24,203.0.113.5');

    $payment = pendingStripePayment('pi_good_ip');
    $payload = succeededEventPayload('pi_good_ip', 'evt_good_ip');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.7',
        ],
        $payload,
    )->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('fails open, not closed, when the allow-list is not configured', function (): void {
    // Deliberately left unset. A stale or missing list must not silently
    // blackhole real payments — signature verification is what actually
    // authenticates this endpoint regardless of this layer.
    config()->set('services.stripe.webhook_allowed_ips', null);

    $payment = pendingStripePayment('pi_no_list');
    $payload = succeededEventPayload('pi_no_list', 'evt_no_list');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.7',
        ],
        $payload,
    )->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('still refuses an unsigned request from an allow-listed IP', function (): void {
    // The allow-list narrows the source; it is not a second way in. A
    // request from a trusted IP with no valid signature must still fail.
    config()->set('services.stripe.webhook_allowed_ips', '198.51.100.0/24');

    $payment = pendingStripePayment('pi_ip_ok_sig_bad');

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '198.51.100.7'],
        json_encode(['type' => 'payment_intent.succeeded'], JSON_THROW_ON_ERROR),
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

/*
 * ── Information disclosure ──────────────────────────────────────────────
 */

it('does not tell a prober why verification failed, or whether an intent exists', function (): void {
    pendingStripePayment('pi_real_one');

    $realIntent = $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
        succeededEventPayload('pi_real_one'),
    );

    $fakeIntent = $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
        succeededEventPayload('pi_does_not_exist'),
    );

    // Identical responses: an unauthenticated caller cannot use this endpoint
    // to discover which PaymentIntent ids this database holds.
    expect($realIntent->status())->toBe($fakeIntent->status())
        ->and($realIntent->json())->toBe($fakeIntent->json())
        // And the body carries no internal detail about the failure.
        ->and($realIntent->json('error'))->not->toContain('secret')
        ->and($realIntent->json('error'))->not->toContain('expected');
});

/*
 * ── Route shape ─────────────────────────────────────────────────────────
 */

it('carries the signature middleware and nothing from the web group', function (): void {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'stripe/webhook');

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain(VerifyStripeWebhookSignature::class)
        ->and($middleware)->toContain(RestrictStripeWebhookIps::class)
        // No web group: no session, no cookies, and — the point — no CSRF
        // token requirement that someone might later "fix" by exempting the
        // route while assuming that was the only protection.
        ->and($middleware)->not->toContain('web')
        ->and($route->methods())->toBe(['POST']);
});

it('is not reachable by GET', function (): void {
    $this->get('/stripe/webhook')->assertStatus(405);
});

/*
 * ── The tolerance floor ─────────────────────────────────────────────────
 */

it('never lets a blank tolerance env var disable the recency check', function (): void {
    /*
     * The trap: env('STRIPE_WEBHOOK_TOLERANCE') on a present-but-empty var
     * returns '', (int)'' is 0, and because the config key *exists* the
     * `config(..., 300)` default in the middleware never fires. stripe-php
     * then guards with `if ($tolerance > 0 && ...)` — so a tolerance of
     * exactly 0 skips the recency check entirely rather than rejecting
     * everything, and a captured request stays replayable forever.
     *
     * config/services.php floors it with max(60, ...) for this reason. This
     * asserts the floor holds, and then that an ancient request is still
     * rejected end to end.
     */
    expect(config('services.stripe.webhook_tolerance'))->toBeGreaterThanOrEqual(60);

    // And the floor is enforced at the point of use too, so setting the
    // config to the dangerous value directly still cannot disable the check.
    config()->set('services.stripe.webhook_tolerance', 0);

    $payment = pendingStripePayment('pi_tolerance');
    $payload = succeededEventPayload('pi_tolerance', 'evt_tolerance');

    // A year old. Only a zero tolerance would let this through.
    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, time() - 31_536_000),
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('refuses a webhook whose paid amount does not match, over HTTP', function (): void {
    // The amount guard, exercised through the real endpoint rather than the
    // Action alone: a genuinely signed event for the right intent, but for a
    // hundredth of the price.
    $payment = pendingStripePayment('pi_http_short', '100.00');

    $payload = succeededEventPayload('pi_http_short', 'evt_http_short', 100);

    $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
        $payload,
        // 200: the signature was valid and Stripe should not retry. The refusal
        // is a domain decision recorded on the event, not a transport failure.
    )->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $event = PaymentEvent::where('stripe_event_id', 'evt_http_short')->first();
    expect($event)->not->toBeNull()
        ->and($event->note)->toContain('does not match');
});

/*
 * ── Signing-secret rotation ─────────────────────────────────────────────
 *
 * Stripe recommends rolling signing secrets periodically. During a roll it
 * keeps the old secret valid for up to 24 hours and signs each event with
 * *every* active secret, putting one signature per secret in the same
 * header. A single-secret implementation rejects events signed only with
 * the new one — silently dropping real payments for a day.
 */

it('accepts an event signed with the current secret while a previous one is configured', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_new_secret');
    config()->set('services.stripe.webhook_secret_previous', 'whsec_old_secret');

    $payment = pendingStripePayment('pi_roll_new');
    $payload = succeededEventPayload('pi_roll_new', 'evt_roll_new');

    $this->call(
        'POST', '/stripe/webhook', [], [], [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, null, 'whsec_new_secret'),
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    )->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('still accepts an event signed with the outgoing secret mid-roll', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_new_secret');
    config()->set('services.stripe.webhook_secret_previous', 'whsec_old_secret');

    $payment = pendingStripePayment('pi_roll_old');
    $payload = succeededEventPayload('pi_roll_old', 'evt_roll_old');

    // The case a one-secret implementation drops.
    $this->call(
        'POST', '/stripe/webhook', [], [], [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, null, 'whsec_old_secret'),
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    )->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('refuses a secret that is neither current nor previous', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_new_secret');
    config()->set('services.stripe.webhook_secret_previous', 'whsec_old_secret');

    $payment = pendingStripePayment('pi_roll_bad');
    $payload = succeededEventPayload('pi_roll_bad', 'evt_roll_bad');

    // Widening the accepted set must not widen it to anything.
    $this->call(
        'POST', '/stripe/webhook', [], [], [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, null, 'whsec_attacker'),
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('rejects the outgoing secret once it is removed from config', function (): void {
    // After the roll completes, STRIPE_WEBHOOK_SECRET_PREVIOUS is unset and
    // the old secret must stop working — otherwise a rolled secret is not
    // actually retired.
    config()->set('services.stripe.webhook_secret', 'whsec_new_secret');
    config()->set('services.stripe.webhook_secret_previous', null);

    $payment = pendingStripePayment('pi_roll_done');
    $payload = succeededEventPayload('pi_roll_done', 'evt_roll_done');

    $this->call(
        'POST', '/stripe/webhook', [], [], [],
        [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, null, 'whsec_old_secret'),
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    )->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});
