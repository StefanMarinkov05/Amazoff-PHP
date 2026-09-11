<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Stripe\StripeClient;

/*
 * 3-D Secure, end to end, against real Stripe (test mode) — the one flow
 * `StripePaymentTest`'s faked client cannot cover
 * (`docs/reference/testing/stripe-testing.md`, "3D Secure is not
 * exercised"). Local runbook only; see the doc's "3D Secure — automated
 * local runbook" section for how to run and debug this.
 *
 * ## Why this needs no `stripe listen`
 *
 * The plan this was scoped from (`~/.claude/plans/zippy-growing-pike.md`)
 * assumed driving the webhook through `stripe listen --forward-to
 * localhost:8080/...`. That does not fit this harness: pest-plugin-browser
 * boots the app **in-process** on a Playwright-assigned ephemeral port
 * (`ServerManager`), rewriting `config('app.url')` to match — so there is no
 * fixed `:8080` for a pre-started listener to target, and nothing external
 * can reach the in-process server anyway.
 *
 * Instead, once the browser has driven the real 3DS challenge and the real
 * PaymentIntent has genuinely reached `succeeded` on Stripe's side, this
 * test constructs the **exact webhook Stripe would have sent** — same
 * shape as `StripeWebhookSecurityTest`'s `succeededEventPayload()` /
 * `stripeSignature()` — signed with the real `STRIPE_WEBHOOK_SECRET`, and
 * POSTs it to `/stripe/webhook` itself. This exercises the identical code
 * path a live `stripe listen` forward would (`VerifyStripeWebhookSignature`
 * → `StripeWebhookController` → `HandleStripeWebhookEvent`), deterministically
 * and without a second running process — the challenge itself is still 100%
 * real Stripe, driven in a real browser.
 *
 * ## Preconditions (skip guards below)
 *
 * - `STRIPE_SECRET` must be a real `sk_test_...` key, not the local
 *   placeholder — this test creates a real Stripe test-mode PaymentIntent.
 * - `STRIPE_WEBHOOK_SECRET` must be a real `whsec_...` value, matching what
 *   `VerifyStripeWebhookSignature` checks against.
 * - No `stripe listen` process is required or used.
 */

function stripeConfiguredForRealTestCalls(): bool
{
    $secret = config('services.stripe.secret');
    $webhookSecret = config('services.stripe.webhook_secret');

    return is_string($secret) && str_starts_with($secret, 'sk_test_')
        && is_string($webhookSecret) && str_starts_with($webhookSecret, 'whsec_');
}

/** Same shape as StripeWebhookSecurityTest's helper — kept local per Pest's per-file scoping. */
function threeDsWebhookSignature(string $payload, string $secret): string
{
    $timestamp = time();
    $digest = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$digest}";
}

it('completes a 3-D Secure challenge and reaches Paid via a Stripe-shaped webhook', function (): void {
    if (! stripeConfiguredForRealTestCalls()) {
        $this->markTestSkipped(
            'STRIPE_SECRET / STRIPE_WEBHOOK_SECRET are not real test-mode values — '.
            'see stripe-testing.md, "3D Secure — automated local runbook".',
        );
    }

    swapFakeCourier();

    $variation = cartVariation(stock: 5, product: [
        'name' => '3DS Runbook Widget',
        'regular_price' => '19.90',
        'min_order_quantity' => 1,
    ]);
    $product = $variation->product;

    // ── Guest: catalogue → product → cart → checkout, card payment ──────
    $page = visit('/catalogue')
        ->navigate("/products/{$product->slug}")
        ->click('Add to cart')
        ->navigate('/cart')
        ->click('Checkout')
        ->assertPathIs('/checkout')
        ->fill('first_name', 'Iva')
        ->fill('last_name', 'Petrova')
        ->fill('email', '3ds-runbook@example.test')
        ->fill('phone', '+359 88 555 0199')
        ->fill('city', 'Sofia')
        ->fill('postcode', '1000')
        ->fill('street', 'bul. Vitosha 1')
        ->click('Econt')
        // Payment method radio defaults to Stripe (PaymentMethod::Stripe is
        // CheckoutPage's initial $payment_method) — no click needed, unlike
        // CheckoutLifecycleTest's explicit "Cash on delivery".
        ->click('Billing address is the same as delivery')
        ->click('Order with obligation to pay')
        // Card path: placeOrder() sets $clientSecret and stays on /checkout
        // rather than redirecting (CheckoutPage::placeOrder, "Stripe: stay
        // on the page and hand the secret to Stripe Elements").
        ->assertPathIs('/checkout');

    $order = Order::query()->where('email', '3ds-runbook@example.test')->sole();

    // ── Fill the real Stripe Payment Element and confirm ─────────────────
    //
    // GOTCHA (confirm on first live run): Stripe's unified Payment Element
    // mounts as one iframe inside #stripe-payment-element, conventionally
    // titled "Secure payment input frame" in Stripe.js's current build. If
    // this selector has drifted, `$page->script("document.querySelector('#stripe-payment-element iframe').outerHTML")`
    // shows the real attributes to fix it against.
    $page->withinFrame('#stripe-payment-element iframe[title="Secure payment input frame"]', function ($frame): void {
        $frame->fill('Card number', '4000002500003155')
            ->fill('Expiration', '12/34')
            ->fill('CVC', '123')
            ->fill('ZIP', '1000');
    });

    $page->click('stripe-submit');

    // ── The 3DS2 challenge — Stripe's own hosted test page ───────────────
    //
    // GOTCHA (confirm on first live run): Stripe's test-mode 3DS2 challenge
    // frame is conventionally named "stripe-challenge-frame"; the test
    // button reads "Complete authentication" (as opposed to "Fail
    // authentication", the other test option). If Stripe has changed either
    // string, `$page->script("document.body.innerHTML")` on the outer page
    // shows the actual challenge iframe's name/src to retarget this.
    $page->withinFrame('iframe[name="stripe-challenge-frame"]', function ($frame): void {
        $frame->click('Complete authentication');
    });

    // Back on the app: confirmPayment's redirect lands on the confirmation
    // page once Stripe finishes the challenge client-side. The *authoritative*
    // status change is the webhook below, not this redirect — CheckoutPage's
    // own comment says a customer closing the tab mid-redirect must still
    // end up paid, which is exactly what this test proves.
    $page->assertPathBeginsWith('/checkout/confirmation');

    // ── Confirm the challenge genuinely succeeded on Stripe's side ───────
    /** @var Payment $payment */
    $payment = $order->payment()->firstOrFail();
    $intentId = $payment->stripe_payment_intent_id;

    if (! is_string($intentId)) {
        throw new RuntimeException('Expected the Stripe payment to carry an intent id after checkout.');
    }

    $intent = app(StripeClient::class)->paymentIntents->retrieve($intentId);

    expect($intent->status)->toBe('succeeded');

    // ── Construct and deliver the webhook Stripe would have sent ─────────
    $payload = json_encode([
        'id' => 'evt_3ds_runbook_'.$order->getKey(),
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'id' => $intentId,
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => $intent->amount_received,
                'amount_received' => $intent->amount_received,
                'currency' => $intent->currency,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $secret = config('services.stripe.webhook_secret');

    if (! is_string($secret)) {
        throw new RuntimeException('services.stripe.webhook_secret must be a string once the skip guard has passed.');
    }

    $deliver = fn () => $this->call(
        'POST',
        '/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => threeDsWebhookSignature($payload, $secret), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $deliver()->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->paid_at)->not->toBeNull()
        ->and(PaymentEvent::where('payment_id', $payment->getKey())->count())->toBe(1);

    // ── Idempotency: a redelivery of the same event moves nothing twice ──
    $deliver()->assertOk();

    expect(PaymentEvent::where('payment_id', $payment->getKey())->count())->toBe(1);
});
