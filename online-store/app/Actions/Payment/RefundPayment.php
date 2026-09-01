<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\RefundNotAllowedException;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Stripe\StripeClient;

/**
 * Sends money back, in full or in part.
 *
 * ## The webhook will echo this, and that is handled
 *
 * Refunding through Stripe makes Stripe fire `charge.refunded`, which
 * `HandleStripeWebhookEvent` also acts on — so the same refund arrives twice,
 * once here and once from Stripe. That is safe because the webhook computes a
 * *delta* against `refunded_amount` and this Action has already written it:
 * the delta comes out zero and the webhook records the event without moving
 * anything. Transitioning here anyway (rather than leaving it to the webhook)
 * is deliberate — an administrator who clicks Refund should see the row
 * change now, not whenever Stripe's delivery lands.
 *
 * ## COD refunds are out of scope here
 *
 * A cash-on-delivery payment has no Stripe charge to reverse; refunding one
 * is a cash process this application does not model. Refused rather than
 * silently marked refunded.
 *
 * Authorizes `refund` on the payment — `PaymentPolicy::refund()`, which
 * `permissions.md` gives to the administrator only. Refunding money is not
 * editing a row.
 *
 * Locks `payments`. ADR-0008 · explanation/concurrency-and-locking.md
 */
final class RefundPayment
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly TransitionPaymentStatus $transitionPaymentStatus,
    ) {}

    /**
     * @param  string|null  $amount  Decimal, e.g. '12.50'. Null refunds
     *                               everything not already refunded.
     *
     * @throws RefundNotAllowedException
     */
    public function handle(Payment $payment, ?string $amount, ?User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $actor): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('refund', $locked);
            }

            if ($locked->method !== PaymentMethod::Stripe) {
                throw RefundNotAllowedException::hasNoIntent($locked);
            }

            if ($locked->stripe_payment_intent_id === null) {
                throw RefundNotAllowedException::hasNoIntent($locked);
            }

            if (! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw RefundNotAllowedException::notPaid($locked);
            }

            $alreadyRefunded = Money::of((string) $locked->refunded_amount);
            $remaining = Money::of((string) $locked->amount)->subtract($alreadyRefunded);

            // Checked inside the lock, so two concurrent refunds cannot each
            // pass an unlocked check and together exceed the payment.
            $requested = $amount === null ? $remaining : Money::of($amount);

            if (! $requested->isPositive()) {
                throw RefundNotAllowedException::exceedsRemaining(
                    $locked,
                    (string) $requested,
                    (string) $remaining,
                );
            }

            if ($requested->isGreaterThan($remaining)) {
                throw RefundNotAllowedException::exceedsRemaining(
                    $locked,
                    (string) $requested,
                    (string) $remaining,
                );
            }

            $this->stripe->refunds->create(
                [
                    'payment_intent' => $locked->stripe_payment_intent_id,
                    'amount' => $requested->toMinorUnits(),
                ],
                [
                    // Keyed on the payment and the running total, so a retry
                    // of *this* refund is idempotent while a genuine second
                    // refund of the same size still goes through.
                    'idempotency_key' => sprintf(
                        'refund-%d-%s-%s',
                        $locked->getKey(),
                        $alreadyRefunded,
                        $requested,
                    ),
                ],
            );

            // Full versus partial is decided by what remains after this
            // refund, not by whether the caller passed an amount — refunding
            // exactly the remainder is a full refund however it was asked for.
            $target = $requested->equals($remaining)
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            // Refunded takes no amount: TransitionPaymentStatus derives the
            // whole sum itself. PartiallyRefunded takes the delta, which is
            // what `$requested` already is.
            return $this->transitionPaymentStatus->handle(
                $locked,
                $target,
                $actor,
                $target === PaymentStatus::Refunded ? null : (string) $requested,
            );
        });
    }
}
