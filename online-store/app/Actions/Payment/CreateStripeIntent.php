<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\StripeIntentNotAllowedException;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Opens the Stripe PaymentIntent a customer's card is charged against, and
 * returns the payment with its intent id stored. §37 criterion 9.
 *
 * ## Why the amount is read off the payment row
 *
 * Never off the caller. `RecordPayment` copied it from the order, and
 * `CreateOrder` recalculated the order's total server-side from the cart —
 * so the figure sent to Stripe traces back to prices this application read
 * from its own database, never to anything a browser submitted. CLAUDE.md,
 * "Totals are always recalculated server-side".
 *
 * ## Idempotency, both halves
 *
 * A customer who reloads the checkout page must not get a second intent, and
 * two concurrent requests must not create two. Both are covered:
 *
 * - The row is locked and re-read, so the "does one exist already" check is
 *   not the check-then-act window it would be unlocked. An existing intent
 *   is retrieved from Stripe and returned rather than replaced.
 * - The create call carries an `idempotency_key` derived from the payment
 *   id, so even if this application's own guard were bypassed, Stripe
 *   returns the original intent rather than creating a second one. Two
 *   defences because the failure is a double charge.
 *
 * ## Authorization
 *
 * None. Like `RecordPayment`, this is called on the customer's own checkout
 * path where there is no permission to hold — the payment belongs to the
 * order the caller just created. `PaymentPolicy` governs the *panel's*
 * access to payment rows, which is a different question.
 *
 * Locks `payments`. ADR-0008 · explanation/concurrency-and-locking.md
 */
final class CreateStripeIntent
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @throws StripeIntentNotAllowedException
     */
    public function handle(Payment $payment, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            // Locked before anything is read off it. The instance the caller
            // holds was hydrated before this lock existed, so its
            // stripe_payment_intent_id may already be stale.
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($locked->method !== PaymentMethod::Stripe) {
                throw StripeIntentNotAllowedException::notAStripePayment($locked);
            }

            // Pending and Processing are the two states an intent belongs to.
            // Paid, Refunded, PartiallyRefunded, Cancelled all mean money has
            // already moved or the attempt is over.
            if (! in_array($locked->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)) {
                throw StripeIntentNotAllowedException::alreadySettled($locked);
            }

            if ($locked->stripe_payment_intent_id !== null) {
                return $locked;
            }

            $intent = $this->stripe->paymentIntents->create(
                [
                    // Integer minor units. Money::toMinorUnits() owns the
                    // conversion so no bcmul with a hand-written scale
                    // appears at a call site — CLAUDE.md's money rule.
                    'amount' => Money::of($locked->amount)->toMinorUnits(),
                    'currency' => mb_strtolower($locked->currency),
                    'automatic_payment_methods' => ['enabled' => true],
                    // What the webhook matches back to a row. The webhook
                    // never trusts an id in the payload body for this —
                    // metadata is set here, by this application, and read
                    // back only as a cross-check against the intent id the
                    // signature already vouched for.
                    'metadata' => [
                        'payment_id' => (string) $locked->getKey(),
                        'order_id' => (string) $locked->order_id,
                    ],
                ],
                [
                    // Stripe's own guard, independent of the lock above. Keyed
                    // on the payment rather than the request, so a retry after
                    // a timeout returns the first intent instead of charging
                    // the customer a second time.
                    'idempotency_key' => 'payment-intent-'.$locked->getKey(),
                ],
            );

            $locked->update(['stripe_payment_intent_id' => $intent->id]);

            return $locked->refresh();
        });
    }

    /**
     * The client secret the browser needs to confirm the intent.
     *
     * Separate from `handle()` because it is not a write and not a fact worth
     * storing: the secret is fetched fresh for the page that renders the card
     * field, and storing it would put a credential in the database that
     * nothing needs to read twice.
     */
    public function clientSecretFor(Payment $payment): ?string
    {
        if ($payment->stripe_payment_intent_id === null) {
            return null;
        }

        $intent = $this->stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);

        return $intent->client_secret;
    }
}
