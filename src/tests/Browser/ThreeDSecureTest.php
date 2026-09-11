<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
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

/**
 * Move `payments.id` past every id this database has already issued, so the
 * Stripe idempotency key this run produces has never been seen before.
 *
 * `CreateStripeIntent` keys its `paymentIntents->create` call on
 * `'payment-intent-'.$payment->id` — deliberately, so a retry after a timeout
 * returns the first intent instead of charging the customer twice. That is
 * correct in production, where ids never repeat. It is actively hostile to a
 * test database that is truncated before every run: `payments.id` restarts at
 * 1, so the *second* run of this test sends idempotency key
 * `payment-intent-1` again and Stripe dutifully replays the intent the first
 * run already drove to `succeeded`. Elements then refuses to initialise with
 * "This PaymentIntent is in a terminal state", tears its own iframe back out
 * of the DOM, and the failure presents as a card form that never appears —
 * nowhere near the real cause.
 *
 * Truncation resets AUTO_INCREMENT, so seeding it from the table's own rows
 * is not enough; the offset has to come from something monotonic that is
 * independent of the database. The clock is, and a `payments` row id is a
 * plain integer with no meaning attached, so a large value is harmless.
 */
function resetStripeIdempotencyScope(): void
{
    DB::statement('ALTER TABLE payments AUTO_INCREMENT = '.(time() % 2_000_000_000));
}

it('completes a 3-D Secure challenge and reaches Paid via a Stripe-shaped webhook', function (): void {
    if (! stripeConfiguredForRealTestCalls()) {
        $this->markTestSkipped(
            'STRIPE_SECRET / STRIPE_WEBHOOK_SECRET are not real test-mode values — '.
            'see stripe-testing.md, "3D Secure — automated local runbook".',
        );
    }

    swapFakeCourier();
    resetStripeIdempotencyScope();

    $variation = cartVariation(stock: 5, product: [
        'name' => '3DS Runbook Widget',
        'regular_price' => '19.90',
        'min_order_quantity' => 1,
    ]);
    $product = $variation->product;

    // ── Guest: catalogue → product → cart → checkout, card payment ──────
    // The intermediate assertions are load-bearing, not decoration: each one
    // waits for the Livewire round-trip before the next navigate(). Without
    // the "Added to cart" wait, /cart renders empty — and the Checkout
    // button only exists in the non-empty branch of cart-page.blade.php, so
    // the next click times out instead of failing with something legible.
    // CheckoutLifecycleTest has the same shape for the same reason.
    $page = visit('/catalogue')
        ->assertSee($product->name)
        ->navigate("/products/{$product->slug}")
        ->assertSee($product->name)
        ->click('Add to cart')
        ->assertSee('Added to cart')
        ->navigate('/cart')
        ->assertSee($product->name)
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
        //
        // This click TICKS the box — CheckoutPage::$billing_same_as_delivery
        // is `false` by default, and the three billing fields are
        // `required_if:billing_same_as_delivery,false`. Without it the submit
        // fails validation ("The billing city field is required when billing
        // same as delivery is false.", + postcode, street) and never reaches
        // Stripe. A signed-in customer with a saved address sees it already
        // ticked; a guest — which is what this test is — does not.
        ->click('Billing address is the same as delivery')
        ->click('Order with obligation to pay')
        // Card path: placeOrder() sets $clientSecret and stays on /checkout
        // rather than redirecting (CheckoutPage::placeOrder, "Stripe: stay
        // on the page and hand the secret to Stripe Elements").
        // NOT assertPathIs('/checkout') — that passes trivially, since the
        // card path never leaves /checkout. Assert on the payment step's own
        // copy instead, so this waits for the placeOrder round-trip (which
        // includes a real Stripe intent call) to actually land.
        ->assertSee('Enter your card to pay');

    $order = Order::query()->where('email', '3ds-runbook@example.test')->sole();

    // ── Fill the real Stripe Payment Element and confirm ─────────────────
    //
    // Wait for the Payment Element to be *laid out*, not merely present:
    // Stripe mounts a 2px placeholder iframe first and swaps in the real
    // ~400px card form a moment later. Asserting presence alone finds the
    // placeholder, whose document has no inputs at all — measured, and the
    // reason the field fills below would otherwise fail intermittently.
    //
    // Polled in short chunks on purpose: `script()` rejects anything still
    // pending after 5s, so a single promise that waits ~25s internally is
    // killed by that cap — which surfaces as a bare "Timeout 5000ms
    // exceeded" with no hint that the budget, not the page, was the problem.
    // Each call below stays well inside the cap; the loop owns the real
    // budget.
    $mounted = false;

    for ($attempt = 0; $attempt < 12 && ! $mounted; $attempt++) {
        $heights = $page->script(
            "Array.from(document.querySelectorAll('#stripe-payment-element iframe'))".
            '.map(f => Math.round(f.getBoundingClientRect().height))'
        );

        $mounted = is_array($heights) && array_filter($heights, fn ($h) => $h > 100) !== [];

        if (! $mounted) {
            $page->script('new Promise(r => setTimeout(() => r(true), 1500))');
        }
    }

    // If this fails, read the Element's own load error before suspecting the
    // selector or the wait — Stripe reports the real cause there, and it is
    // usually a *terminal PaymentIntent* rather than anything about layout.
    // See `resetStripeIdempotencyScope()` above for why that happens here.
    expect($mounted)->toBeTrue('Stripe never swapped its 2px placeholder for the real card form.');

    // Field names and labels below are the live ones, read out of the
    // rendered frame rather than assumed: inputs are `number` / `expiry` /
    // `cvc`, labelled "Card number" / "Expiration date" / "Security code".
    // There is no postcode field in this Element's configuration.
    $page->withinFrame('#stripe-payment-element iframe[title="Secure payment input frame"]', function ($frame): void {
        $frame->fill('number', '4000002500003155')
            ->fill('expiry', '12/34')
            ->fill('cvc', '123');
    });

    $page->click('stripe-submit');

    // ── The 3DS2 challenge — Stripe's own hosted test page ───────────────
    //
    // The challenge is **doubly nested**, and the outer frame cannot be
    // targeted by name or title — both are useless (`name` is a random
    // `__privateStripeFrame<n>`, `title` is empty). Its `src` is the only
    // stable handle. Structure, read out of a live Chromium session:
    //
    //   iframe[src*="three-ds-2-challenge"]   ← outer, random name, no title
    //     └─ dialog (a "Cancel" button lives here, not in the inner frame)
    //          └─ iframe[name="stripe-challenge-frame"]
    //               └─ heading "3D Secure 2 Test Page"
    //                  button "Fail" | button "Complete"
    //
    // The two buttons carry stable ids — `#test-source-authorize-3ds`
    // ("Complete") and `#test-source-fail-3ds` ("Fail") — and those are what
    // this targets. Matching on the visible text instead does NOT work here:
    // each button is paired with a hidden `<input name="challenge">`
    // (`allow` / `deny`), and a text lookup resolves to a wrapper rather than
    // the submit button, so the click lands on nothing and the dialog simply
    // stays open — a redirect timeout, with no hint that the selector was the
    // problem. Read out of the live frame, not assumed.
    $page->withinFrame('iframe[src*="three-ds-2-challenge"]', function ($outer): void {
        $outer->withinFrame('iframe[name="stripe-challenge-frame"]', function ($challenge): void {
            // Wait for `readyState === 'complete'` before clicking. Stripe's
            // test page binds its submit handler on load, and `withinFrame`
            // resolves the frame as soon as it is attached — while the
            // document is still "interactive". A click that lands in that
            // window is accepted by Playwright and does nothing: the button
            // is present and clickable, so no error is raised, but the form
            // never submits and the dialog just stays open. Measured: the
            // button exists both before and after such a click, and
            // `location.href` never changes.
            $challenge->script(
                "new Promise(r => { if (document.readyState === 'complete') return r(true);".
                "window.addEventListener('load', () => r(true)); })"
            );

            // Submit the "allow" form rather than clicking the button.
            //
            // The page is two plain POST forms to the same ACS endpoint,
            // distinguished only by a single hidden input — `challenge=deny`
            // (the "Fail" button) and `challenge=allow` ("Complete") — with
            // no JavaScript handler on either. Playwright's click reports
            // success on `#test-source-authorize-3ds` but does not trigger
            // native submission here: measured, the button is still present
            // afterwards and `location.href` is unchanged, so the dialog just
            // stays open and the failure surfaces 20s later as "path is
            // /checkout" with nothing pointing at the click. Submitting the
            // form the button belongs to does exactly what pressing it does.
            $submitted = $challenge->script(
                "(() => { const f = Array.from(document.querySelectorAll('form')).find(".
                "f => Array.from(f.elements).some(e => e.name === 'challenge' && e.value === 'allow'));".
                'if (! f) { return false; } f.submit(); return true; })()'
            );

            expect($submitted)->toBeTrue('The 3DS test page had no challenge=allow form to submit.');
        });
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
