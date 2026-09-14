<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Actions\Payment\HandleStripeWebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Event as StripeEvent;
use Throwable;

/**
 * Stripe's webhook endpoint. §37 criteria 10 and 11.
 *
 * Thin by the same rule every other caller follows: the request is already
 * authenticated by `VerifyStripeWebhookSignature`, so this reads the
 * verified event off the request and hands it to the Action. It never
 * touches the raw body — re-parsing would reopen the gap between what the
 * signature covered and what gets acted on.
 *
 * ## Why almost everything returns 200
 *
 * Stripe retries any non-2xx with backoff for up to three days. A 500 for a
 * condition that will never succeed — an event type this application ignores,
 * an intent it has never heard of — buys nothing and fills the log with the
 * same event for days. Those are acknowledged.
 *
 * A 500 is reserved for the case where a retry genuinely might succeed: an
 * unexpected exception, most likely the database being briefly unavailable.
 * There, being retried is exactly what should happen, and
 * `HandleStripeWebhookEvent`'s idempotency makes the retry safe.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, HandleStripeWebhookEvent $handleEvent): JsonResponse
    {
        $event = $request->attributes->get('stripe_event');

        if (! $event instanceof StripeEvent) {
            // Unreachable through the route as registered — the middleware
            // sets this or returns 400 before reaching here. Guarded anyway,
            // because "the middleware was removed from the route" is a
            // plausible future edit and it must not fail open.
            Log::critical('Stripe webhook reached the controller without a verified event.');

            return response()->json(['error' => 'Unverified.'], 400);
        }

        try {
            $handleEvent->handle($event);
        } catch (Throwable $e) {
            // Retryable. Logged with the event id so a repeat is traceable to
            // one delivery rather than looking like many separate failures.
            Log::error('Stripe webhook processing failed; asking Stripe to retry.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed.'], 500);
        }

        return response()->json(['received' => true]);
    }
}
