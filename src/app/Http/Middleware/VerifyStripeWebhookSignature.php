<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Proves a webhook request actually came from Stripe.
 *
 * The webhook route is CSRF-exempt — it must be, since Stripe does not carry
 * a session token — which means **this middleware is the entire
 * authentication story for that endpoint.** CLAUDE.md states it as a pair:
 * "CSRF-excluded *and* signature-verified. One without the other is a
 * free-products vulnerability." Without this, any anonymous POST could
 * announce `payment_intent.succeeded` and mark an order paid.
 *
 * ## What it actually checks
 *
 * `Webhook::constructEvent()` recomputes an HMAC-SHA256 over
 * `{timestamp}.{raw body}` keyed with the endpoint's signing secret and
 * compares it to the `Stripe-Signature` header in constant time. That gives
 * three properties at once:
 *
 * - **Authenticity** — only a holder of the signing secret can produce it.
 * - **Integrity** — the body is inside the MAC, so flipping a single
 *   character of the amount invalidates it.
 * - **Freshness** — the timestamp is inside the MAC too, and is rejected
 *   outside the configured tolerance, so a captured request cannot be
 *   replayed indefinitely. Replay *within* the window is still possible,
 *   which is why `HandleStripeWebhookEvent` is independently idempotent on
 *   `payment_events.stripe_event_id`. Two defences, because the signature
 *   alone does not make replay harmless.
 *
 * ## The raw body matters
 *
 * `$request->getContent()`, never `$request->all()` or a re-encoded array.
 * The MAC covers the exact bytes Stripe sent; re-serialising JSON reorders
 * keys and changes whitespace, and the signature then never matches. This is
 * also why the route must not sit behind anything that consumes the body.
 *
 * ## It fails closed
 *
 * A missing secret raises rather than skipping verification. A misconfigured
 * deployment that quietly accepted unsigned webhooks is exactly the failure
 * this class exists to prevent, and "the secret was blank in .env" is the
 * most likely way to reach it.
 */
class VerifyStripeWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = $this->acceptedSecrets();

        if ($secrets === []) {
            // 500, not 400: the request may well be legitimate — this is the
            // server being misconfigured, and it must be loud. Never fall
            // through to the handler.
            Log::critical('Stripe webhook secret is not configured; refusing every webhook.');

            return response()->json(['error' => 'Webhook not configured.'], 500);
        }

        $signature = $request->header('Stripe-Signature');

        if (! is_string($signature) || $signature === '') {
            Log::warning('Stripe webhook rejected: no signature header.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Missing signature.'], 400);
        }

        // Floored here as well as in config/services.php, and for a reason
        // beyond belt-and-braces: stripe-php guards its recency check with
        // `if ($tolerance > 0)`, so a tolerance of exactly 0 does not reject
        // everything — it silently skips the check and makes a captured
        // request replayable forever. A blank STRIPE_WEBHOOK_TOLERANCE casts
        // to 0, and because the config key then exists, config()'s own
        // default never fires. Clamping at the point of use is what makes
        // that unreachable however the value arrived.
        $configuredTolerance = config('services.stripe.webhook_tolerance', 300);

        if (! is_scalar($configuredTolerance)) {
            throw new InvalidArgumentException('Config value [services.stripe.webhook_tolerance] must be a scalar value.');
        }

        $tolerance = max(60, (int) $configuredTolerance);

        // Always assigned before it is read: $secrets is non-empty (the
        // early return above guarantees it), so the loop runs at least once,
        // and reaching the code below means every iteration threw.
        $failure = null;

        /*
         * Every accepted secret is tried, because during a roll Stripe signs
         * each event with *every* currently active secret and puts one
         * signature per secret in the same header. stripe-php's verifier
         * takes a single secret, so a one-secret implementation rejects
         * events signed only with the new one — silently dropping payments
         * for up to the 24 hours the old secret stays valid. Stripe
         * recommends rolling periodically, which makes this a "when".
         *
         * No timing oracle: constructEvent() compares in constant time per
         * secret, and the loop always runs to a decision whose *shape* does
         * not depend on which secret matched.
         */
        foreach ($secrets as $secret) {
            try {
                $event = Webhook::constructEvent(
                    $request->getContent(),
                    $signature,
                    $secret,
                    $tolerance,
                );

                // The verified event, passed on the request so the controller
                // never re-parses the body — re-parsing would reintroduce the
                // gap between "what was signed" and "what was acted on".
                $request->attributes->set('stripe_event', $event);

                return $next($request);
            } catch (SignatureVerificationException $e) {
                // Hold and try the next secret: a configured-but-superseded
                // previous secret failing is the normal case mid-roll.
                $failure = $e;
            } catch (UnexpectedValueException $e) {
                // Malformed JSON is a property of the body, not of the
                // secret, so no other secret will do better.
                Log::warning('Stripe webhook rejected: payload was not valid JSON.', [
                    'ip' => $request->ip(),
                    'reason' => $e->getMessage(),
                ]);

                return response()->json(['error' => 'Invalid payload.'], 400);
            }
        }

        // Deliberately does not echo the reason back. A caller probing the
        // endpoint learns only that it failed, not whether the timestamp, the
        // digest, or the header shape was wrong — and not how many secrets
        // are configured.
        Log::warning('Stripe webhook rejected: signature verification failed against every accepted secret.', [
            'ip' => $request->ip(),
            'secrets_tried' => count($secrets),
            'reason' => $failure->getMessage(),
        ]);

        return response()->json(['error' => 'Invalid signature.'], 400);
    }

    /**
     * Every signing secret a request may legitimately be signed with.
     *
     * `STRIPE_WEBHOOK_SECRET` is the current one.
     * `STRIPE_WEBHOOK_SECRET_PREVIOUS` is optional and exists solely for the
     * window during a roll where Stripe still signs with both — set it to the
     * outgoing secret before rolling, remove it once the old secret expires.
     * Leaving it set indefinitely widens the accepted set for no reason, so
     * it is a temporary value by design.
     *
     * @return list<string>
     */
    private function acceptedSecrets(): array
    {
        $secrets = [
            config('services.stripe.webhook_secret'),
            config('services.stripe.webhook_secret_previous'),
        ];

        return array_values(array_filter(
            $secrets,
            static fn (mixed $secret): bool => is_string($secret) && $secret !== '',
        ));
    }
}
