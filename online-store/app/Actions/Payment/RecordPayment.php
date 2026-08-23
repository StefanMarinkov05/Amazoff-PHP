<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentAlreadyRecordedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens the payment row an order pays through.
 *
 * The domain half of slice 6, deliberately separated from the Stripe half:
 * every Stripe column on `payments` is nullable, so a payment can exist —
 * and be transitioned, refunded, and reported on — before any connector is
 * built. `CreateStripeIntent` will later fill `stripe_payment_intent_id` on
 * a row this Action opened rather than creating its own.
 *
 * **Amount is recalculated from the order, never passed in.** §28 and §37
 * criterion 8 both require the server to own the total, and an Action that
 * accepted an amount would be the one place that rule could be bypassed
 * without anything noticing.
 *
 * Both methods open at `Pending`, which is what `PaymentStatus`'s matrix
 * expects as the entry point. The difference between them is what happens
 * next, not where they start: a Stripe payment moves on a webhook, a
 * cash-on-delivery payment moves on courier remittance (CLAUDE.md's COD
 * scope note).
 *
 * Contested: `Order::payment()` is a `HasOne`, but nothing in the schema
 * stops two rows. Locks the order and re-checks, so two concurrent
 * checkouts of the same order produce one payment and one refusal rather
 * than two payments.
 *
 * Authorizes nothing. `PaymentPolicy::create()` returns false outright — a
 * payment exists because a customer checked out or Stripe said so, never
 * because someone pressed a button — so there is no actor whose permission
 * to check. The `?User` is recorded for symmetry with its siblings and to
 * keep ADR-0007's "actor last, no default" shape.
 * ADR-0004 · ADR-0007 · reference/write-rules/order.md
 */
final class RecordPayment
{
    /**
     * @throws PaymentAlreadyRecordedException
     */
    public function handle(Order $order, PaymentMethod $method, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($order, $method): Payment {
            // Locked before the existence check, not after: an unlocked read
            // is the check-then-act window two simultaneous checkouts would
            // both pass through.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->payment()->exists()) {
                throw new PaymentAlreadyRecordedException($locked);
            }

            /** @var Payment $payment */
            $payment = $locked->payment()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'currency' => 'EUR',
                // Read off the order, never off the caller. The order's own
                // total was itself recalculated server-side by CreateOrder.
                'amount' => $locked->total_amount,
                'refunded_amount' => '0.00',
            ]);

            return $payment;
        });
    }
}
