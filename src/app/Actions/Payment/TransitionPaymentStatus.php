<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\IllegalPaymentStatusTransitionException;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The single writer of `payments.status`, and of the money columns a status
 * change implies.
 *
 * ADR-0004's payment equivalent of `TransitionOrderStatus`, and deliberately
 * the same shape: legality from `PaymentStatus::canTransitionTo()`,
 * authorization from the policy, the write and its side effects inside one
 * transaction under a lock on the contested row.
 *
 * ## Why the no-op is not the same as the order one
 *
 * `TransitionOrderStatus` treats `$from === $to` as a clean no-op, which is
 * safe *only because* `OrderStatus`'s graph is acyclic — no legitimate
 * second visit to a status exists, so a repeat can only be a double submit.
 *
 * `PaymentStatus` is **not** acyclic. `PartiallyRefunded` lists itself:
 * refunding 20 EUR of a 100 EUR order twice is two real events that must both
 * land, and collapsing the second into a no-op would silently lose a refund.
 * So a self-transition is legal here when the matrix says so, and refused
 * when it does not — there is no special case, only the matrix.
 *
 * The cost of that is losing the free double-submit protection the order
 * Action gets. A retried `charge.refunded` webhook must therefore be made
 * idempotent by its Stripe event id — `payment_events.stripe_event_id` is
 * unique for exactly this — rather than by this Action noticing a repeat.
 * That is slice 6's job when it arrives; this Action is not idempotent and
 * does not pretend to be.
 *
 * ## Money
 *
 * `refunded_amount` accumulates through `App\Support\Money`, never float,
 * and never beyond
 * `amount`. A refund that would exceed the payment is refused before the
 * status is written — the check has to happen inside the same lock, because
 * two concurrent partial refunds that each pass an unlocked check would
 * together overshoot.
 *
 * Locks `payments`. Does **not** touch `orders.status`: whether a paid
 * payment advances its order is a separate decision with its own policy
 * (ADR-0011 routes cancel and refund to the administrator), and coupling
 * them here would let a webhook move an order without anyone authorising it.
 *
 * Authorizes `update` on the payment, except `refund`, which
 * `PaymentPolicy::refund()` gates separately — refunding money is not
 * editing a row.
 * ADR-0004 · ADR-0011 · reference/write-rules/order.md
 */
final class TransitionPaymentStatus
{
    /**
     * @param  string|null  $refundAmount  Required when moving to a refunded
     *                                     status, forbidden otherwise. A
     *                                     decimal string, never a float.
     *
     * @throws IllegalPaymentStatusTransitionException
     * @throws InvalidArgumentException
     */
    public function handle(
        Payment $payment,
        PaymentStatus $to,
        ?User $actor,
        ?string $refundAmount = null,
    ): Payment {
        return DB::transaction(function () use ($payment, $to, $actor, $refundAmount): Payment {
            // Locked before the status is read. Reading `$payment->status`
            // off the instance the caller holds protects nothing — it was
            // hydrated before the lock existed.
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new IllegalPaymentStatusTransitionException($locked, $from, $to);
            }

            $isRefund = in_array($to, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true);

            if ($actor !== null) {
                Gate::forUser($actor)->authorize($isRefund ? 'refund' : 'update', $locked);
            }

            $attributes = ['status' => $to];

            if ($to === PaymentStatus::Paid) {
                // Stamped once, on first arrival. `Failed => Paid` is a legal
                // move (a late Stripe success after a timeout), and that is
                // the moment the money actually arrived, so there is no
                // earlier value to preserve.
                $attributes['paid_at'] = Carbon::now();
            }

            if ($isRefund) {
                $attributes['refunded_amount'] = $this->refundedTotal($locked, $to, $refundAmount);
            } elseif ($refundAmount !== null) {
                throw new InvalidArgumentException(
                    'A refund amount is only meaningful when moving to a refunded status.'
                );
            }

            $locked->update($attributes);

            return $locked->refresh();
        });
    }

    /**
     * The new `refunded_amount`, in decimal strings throughout.
     *
     * A full refund is the whole amount by definition and takes no argument —
     * asking a caller to supply it is asking them to get it wrong. A partial
     * refund accumulates onto whatever was already refunded, and may not
     * exceed the payment.
     *
     * @throws InvalidArgumentException
     */
    private function refundedTotal(Payment $payment, PaymentStatus $to, ?string $refundAmount): string
    {
        if ($to === PaymentStatus::Refunded) {
            return (string) $payment->amount;
        }

        if ($refundAmount === null) {
            throw new InvalidArgumentException(
                'A partial refund needs an amount; pass one or move to Refunded for the full sum.'
            );
        }

        if (! Money::of($refundAmount)->isPositive()) {
            throw new InvalidArgumentException('A refund amount must be positive.');
        }

        $total = Money::of((string) $payment->refunded_amount)->add(Money::of($refundAmount));

        // Checked inside the caller's lock. Two concurrent partial refunds
        // that each passed an unlocked check would both write, and together
        // exceed the payment.
        if ($total->isGreaterThan(Money::of((string) $payment->amount))) {
            throw new InvalidArgumentException(sprintf(
                'Refunding %s would take the total to %s, above the payment of %s.',
                $refundAmount,
                (string) $total,
                (string) $payment->amount,
            ));
        }

        return (string) $total;
    }
}
