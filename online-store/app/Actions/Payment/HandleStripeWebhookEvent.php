<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event as StripeEvent;

/**
 * Applies one Stripe webhook event to the payment it belongs to.
 * §37 criteria 10 and 11.
 *
 * ## This Action assumes the signature was already verified
 *
 * `VerifyStripeWebhookSignature` middleware does that, and nothing reaches
 * here without passing it. The split is deliberate and is where
 * `misc/actions-plan.md` put it: verification is a property of the HTTP
 * request (raw body, header, tolerance window), not of the domain write, and
 * an Action that re-read the raw request body would be reaching back through
 * a layer it should not know about.
 *
 * The consequence worth stating: **every field read below is trusted only
 * because the signature vouched for the whole payload.** Take the middleware
 * away and this becomes "anyone who can POST can mark any order paid".
 *
 * ## Idempotency is the UNIQUE index, not a check
 *
 * §37 #11. `payment_events.stripe_event_id` is `UNIQUE`, and this inserts
 * that row *first*, inside the same transaction as the status change. A
 * duplicate delivery — Stripe retries on any non-2xx, and at-least-once is
 * its documented contract — hits the constraint and is caught as "already
 * processed".
 *
 * Deliberately not `if (PaymentEvent::where(...)->exists())`: that is
 * check-then-act, and two concurrent deliveries of the same event both pass
 * the check before either inserts. CLAUDE.md states the rule; this is the
 * case it was written for. The insert-first ordering also means a failure
 * *after* the insert rolls the event row back, so a genuine failure stays
 * retryable rather than being permanently swallowed as "seen".
 *
 * ## An unknown intent is ignored, not thrown
 *
 * A 500 makes Stripe retry the same event on a schedule for days. An event
 * for an intent this database has never seen is not a defect worth that — it
 * is a test-mode event, or another environment sharing the endpoint. Logged
 * and acknowledged.
 *
 * Locks `payments`. ADR-0008 · explanation/concurrency-and-locking.md
 */
final class HandleStripeWebhookEvent
{
    public function __construct(private readonly TransitionPaymentStatus $transitionPaymentStatus) {}

    /**
     * Stripe event types this application acts on, and the payment status
     * each one means. Anything not listed is acknowledged and ignored —
     * Stripe sends far more than this, and reacting to an event whose
     * meaning has not been thought through is worse than ignoring it.
     *
     * `charge.refunded` is absent deliberately: a refund's target status
     * depends on *how much* was refunded, so it is resolved in
     * `statusFor()` rather than by a flat lookup.
     */
    private const STATUS_BY_EVENT_TYPE = [
        'payment_intent.succeeded' => PaymentStatus::Paid,
        'payment_intent.payment_failed' => PaymentStatus::Failed,
        'payment_intent.canceled' => PaymentStatus::Cancelled,
        'payment_intent.processing' => PaymentStatus::Processing,
        // A customer disputing a charge through their bank. Reachable only
        // from Paid/PartiallyRefunded — the states where money arrived — so
        // an out-of-order delivery against any other state is recorded and
        // refused by the matrix rather than applied. Not terminal: a dispute
        // won returns to Paid, one lost ends at Refunded — both closing
        // events are resolved in statusFor(), the same way charge.refunded
        // is, since the target depends on the payload (dispute.status), not
        // the event type alone.
        'charge.dispute.created' => PaymentStatus::Disputed,
    ];

    public function handle(StripeEvent $event): ?PaymentEvent
    {
        // Stripe types Event::$data and $data->object as non-null, and
        // Webhook::constructEvent() will not build an Event without them, so
        // there is no null branch to guard here — Larastan flags one as
        // unreachable.
        $object = $event->data->object;

        $intentId = $this->intentIdFrom($event, $object);

        if ($intentId === null) {
            return null;
        }

        $payment = Payment::query()->where('stripe_payment_intent_id', $intentId)->first();

        if ($payment === null) {
            // Not an error. See the class docblock — throwing here would make
            // Stripe retry an event this database can never act on.
            Log::info('Stripe webhook for an unknown PaymentIntent; ignoring.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'payment_intent' => $intentId,
            ]);

            return null;
        }

        try {
            return DB::transaction(fn (): PaymentEvent => $this->apply($event, $object, $payment));
        } catch (UniqueConstraintViolationException) {
            // The event row already existed: a duplicate delivery. §37 #11.
            // Caught rather than checked for, so two concurrent deliveries of
            // the same event cannot both pass a check and both apply.
            Log::info('Duplicate Stripe webhook event ignored.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return null;
        }
    }

    /**
     * The write itself, inside the caller's transaction.
     */
    private function apply(StripeEvent $event, object $object, Payment $payment): PaymentEvent
    {
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
        $from = $locked->status;

        $target = $this->statusFor($event->type, $object, $locked);

        // The event row goes in before the status changes, and carries the
        // UNIQUE that makes this idempotent. A duplicate raises here, before
        // anything else in the transaction has run.
        $paymentEvent = PaymentEvent::query()->create([
            'payment_id' => $locked->getKey(),
            'stripe_event_id' => $event->id,
            'event_type' => mb_substr($event->type, 0, 50),
            'status_before' => $from,
            'status_after' => $target ?? $from,
            'payload' => $this->payloadFor($object),
            'processed_at' => Carbon::now(),
            'note' => $target === null ? 'Acknowledged; no status change for this event type.' : null,
        ]);

        /*
         * `$target === $from` usually means there is nothing to do — Stripe
         * re-sending a different event that resolves to the status the
         * payment already holds.
         *
         * PartiallyRefunded is the exception, and `PaymentStatus`'s matrix
         * says so out loud by listing it as reachable from itself: a second
         * partial refund leaves the *status* unchanged while the *amount*
         * moves. Returning early here would silently drop every refund after
         * the first, which is what an earlier draft of this Action did.
         */
        $isFurtherPartialRefund = $target === PaymentStatus::PartiallyRefunded
            && $from === PaymentStatus::PartiallyRefunded;

        if ($target === null) {
            // Note already set at insert: an event type this app ignores.
            return $paymentEvent;
        }

        if ($target === $from && ! $isFurtherPartialRefund) {
            // Recorded, nothing to move — the payment already holds this
            // status. Noted explicitly rather than left blank: this table is
            // read to reconcile a payment, and an event that applied nothing
            // must not look identical to one that applied the transition.
            // Two concurrent deliveries of *different* events that both mean
            // "succeeded" land here for the loser.
            $paymentEvent->update([
                'note' => sprintf('Not applied: payment was already %s.', $from->value),
            ]);

            return $paymentEvent;
        }

        if (! $from->canTransitionTo($target)) {
            // Out-of-order delivery: Stripe does not guarantee ordering, so a
            // `processing` event can arrive after `succeeded`. Refusing to
            // move backwards is correct; failing the request is not, because
            // that would make Stripe retry an event that will never apply.
            Log::info('Stripe webhook implies an illegal transition; recorded but not applied.', [
                'event_id' => $event->id,
                'from' => $from->value,
                'to' => $target->value,
            ]);

            $paymentEvent->update([
                'status_after' => $from,
                'note' => sprintf('Not applied: %s cannot transition to %s.', $from->value, $target->value),
            ]);

            return $paymentEvent;
        }

        /*
         * Never mark paid for the wrong amount or the wrong currency —
         * `payment_intent.succeeded` only, not every event that happens to
         * resolve to a Paid target.
         *
         * Stripe's guidance, and plain prudence: the event says a charge
         * succeeded, but it does not follow that it succeeded for what this
         * order costs. A partial capture, an intent created against a
         * different amount, or a currency mix-up would all otherwise mark the
         * order Paid and ship goods for less than they cost.
         *
         * `amount_received` only, not `?? $object->amount`. `amount` is what
         * was *requested* on the intent — the figure this Action itself put
         * there via CreateStripeIntent — while `amount_received` is what
         * Stripe actually captured. Falling back to the requested amount
         * would make this guard compare CreateStripeIntent's own number
         * against itself and pass regardless of what was truly charged,
         * which defeats the point of checking at all.
         *
         * Restricted to the one event type that actually carries
         * `amount_received`: a Dispute object (`charge.dispute.closed`,
         * `status: won`, also a Paid target) has no such field, so gating on
         * `$target === Paid` alone made every won dispute fail this guard —
         * `$received` was always null, always a mismatch, and the payment
         * silently stayed Disputed. Found by the regression test for that
         * exact path, not by inspection.
         *
         * Amount is compared in minor units so the comparison is
         * integer-exact and never a decimal-string mismatch. Currency is
         * compared case-insensitively — Stripe lowercases, this schema does
         * not enforce a case on `payments.currency`. Recorded and not
         * applied rather than thrown, for the same reason every other
         * refusal here is: throwing makes Stripe retry an event that can
         * never apply.
         */
        if ($target === PaymentStatus::Paid && $event->type === 'payment_intent.succeeded') {
            $received = $object->amount_received ?? null;
            $expected = Money::of((string) $locked->amount)->toMinorUnits();
            $receivedCurrency = is_string($object->currency ?? null) ? mb_strtolower($object->currency) : null;
            $expectedCurrency = mb_strtolower($locked->currency);

            $amountMismatch = ! is_int($received) || $received !== $expected;
            $currencyMismatch = $receivedCurrency !== $expectedCurrency;

            if ($amountMismatch || $currencyMismatch) {
                Log::warning('Stripe webhook reported a paid amount or currency that does not match the payment.', [
                    'event_id' => $event->id,
                    'payment_id' => $locked->getKey(),
                    'expected_minor' => $expected,
                    'received_minor' => $received,
                    'expected_currency' => $expectedCurrency,
                    'received_currency' => $receivedCurrency,
                ]);

                $paymentEvent->update([
                    'status_after' => $from,
                    // var_export on the received values only: both can be
                    // null (a malformed or unexpected payload) and "charged
                    // NULL" reads better than an empty gap. The expected
                    // values come from our own row and are always present.
                    'note' => sprintf(
                        'Not applied: charged %s %s does not match the expected %d %s.',
                        var_export($received, true),
                        var_export($receivedCurrency, true),
                        $expected,
                        $expectedCurrency,
                    ),
                ]);

                return $paymentEvent;
            }
        }

        /*
         * The delta, not Stripe's figure.
         *
         * Stripe's `amount_refunded` is the *cumulative* total refunded
         * against that charge, while TransitionPaymentStatus::refundedTotal()
         * *accumulates* what it is given onto `refunded_amount`. Passing
         * Stripe's number straight through would therefore double-count every
         * refund after the first — refund 10 then 10 again and the row would
         * read 30, not 20.
         *
         * A full refund needs no amount at all: the Action derives it from
         * the payment, deliberately, so a caller cannot get it wrong.
         */
        $refundAmount = null;

        if ($target === PaymentStatus::PartiallyRefunded) {
            $cumulative = $this->refundedTotalFrom($object);

            if ($cumulative === null) {
                return $paymentEvent;
            }

            $delta = Money::of($cumulative)->subtract(Money::of((string) $locked->refunded_amount));

            // Zero or negative means this event carries nothing new — a
            // duplicate that slipped past the event-id guard, or an
            // out-of-order delivery of an earlier refund. Recorded, not
            // applied: a non-positive amount would make the Action throw.
            if (! $delta->isPositive()) {
                $paymentEvent->update([
                    'status_after' => $from,
                    'note' => 'Not applied: refund total had already been recorded.',
                ]);

                return $paymentEvent;
            }

            $refundAmount = (string) $delta;
        }

        // Null actor: Stripe is the actor, and TransitionPaymentStatus skips
        // authorization for a null actor by design — the system acting on its
        // own behalf holds no permissions. ADR-0007.
        $this->transitionPaymentStatus->handle($locked, $target, null, $refundAmount);

        return $paymentEvent;
    }

    /**
     * The PaymentIntent id an event refers to.
     *
     * A `payment_intent.*` event's object *is* the intent. Everything else
     * this application listens to — `charge.refunded`,
     * `charge.dispute.created` — carries the intent it belongs to on
     * `payment_intent`.
     *
     * That field is typed `null|PaymentIntent|string` by Stripe: it is an id
     * normally, but an *expanded object* if the endpoint or the request asked
     * for expansion. Reading it as a string only would return null on an
     * expanded payload and silently ignore a real dispute, so both shapes are
     * accepted.
     */
    private function intentIdFrom(StripeEvent $event, object $object): ?string
    {
        $id = str_starts_with($event->type, 'charge.')
            ? ($object->payment_intent ?? null)
            : ($object->id ?? null);

        // An expanded PaymentIntent rather than its id.
        if (is_object($id)) {
            $id = $id->id ?? null;
        }

        if (! is_string($id) || $id === '') {
            Log::warning('Stripe webhook carried no usable PaymentIntent id.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return null;
        }

        return $id;
    }

    /**
     * The payment status an event implies, or null to record without moving.
     */
    private function statusFor(string $eventType, object $object, Payment $payment): ?PaymentStatus
    {
        if ($eventType === 'charge.refunded') {
            $refunded = $this->refundedTotalFrom($object);

            if ($refunded === null) {
                return null;
            }

            // Full versus partial is decided by comparing against the amount
            // actually charged, not by trusting a boolean in the payload.
            return Money::of($refunded)->isLessThan(Money::of($payment->amount))
                ? PaymentStatus::PartiallyRefunded
                : PaymentStatus::Refunded;
        }

        if ($eventType === 'charge.dispute.closed') {
            return $this->disputeOutcomeFrom($object);
        }

        return self::STATUS_BY_EVENT_TYPE[$eventType] ?? null;
    }

    /**
     * `charge.dispute.closed` fires for more than a decided dispute — a
     * `warning_closed` inquiry (never became a formal chargeback) uses the
     * same event type. Only `won`/`lost` are decisions this application
     * acts on; anything else is acknowledged and recorded without a status
     * change, same reasoning as the class docblock gives for an unlisted
     * event type — a status this application has not thought through is not
     * applied on a guess. `PaymentStatus::Disputed::allowedTransitions()`
     * is what actually enforces `won => Paid` / `lost => Refunded`; this
     * only resolves *which* of the two the payload means.
     */
    private function disputeOutcomeFrom(object $object): ?PaymentStatus
    {
        $status = $object->status ?? null;

        return match ($status) {
            'won' => PaymentStatus::Paid,
            'lost' => PaymentStatus::Refunded,
            default => null,
        };
    }

    /**
     * Stripe reports refunds in minor units; the schema stores a decimal.
     */
    private function refundedTotalFrom(object $object): ?string
    {
        $minor = $object->amount_refunded ?? null;

        if (! is_int($minor)) {
            return null;
        }

        return bcdiv((string) $minor, '100', Money::SCALE);
    }

    /**
     * What gets stored in `payment_events.payload`.
     *
     * Deliberately not the whole event. A Stripe object can carry the
     * cardholder's name, billing address, and the card's last four — §33's
     * data-minimisation point applies to a debugging column as much as
     * anywhere else, and this table is readable from the panel. Only the
     * fields an operator needs to reconcile a payment are kept.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(object $object): array
    {
        return array_filter([
            'id' => $object->id ?? null,
            'status' => $object->status ?? null,
            'amount' => $object->amount ?? null,
            'amount_refunded' => $object->amount_refunded ?? null,
            'currency' => $object->currency ?? null,
            'failure_message' => $object->last_payment_error->message ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
