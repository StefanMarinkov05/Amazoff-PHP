<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Cancels card orders that reached the payment step and were never paid for,
 * releasing the stock they hold. ADR-0022.
 *
 * The abandoned-checkout case: a customer reaches Stripe Elements, closes
 * the tab, and nothing else ever happens. `ReserveStock` held the units
 * inside `CreateOrder`'s transaction, and §20's four release triggers all
 * run through `TransitionOrderStatus` — none of which any caller reaches for
 * an order nobody is looking at any more. Without this sweep the units are
 * unsellable indefinitely.
 *
 * ## Why this composes no inventory call
 *
 * It cancels through `TransitionOrderStatus` and stops there. ADR-0011 puts
 * the inventory effect *inside* that Action, keyed by target status, exactly
 * so a caller reaching `Cancelled` cannot forget to release. Calling
 * `ReleaseStock` here as well would double-release and throw from
 * `ReleaseStock`'s own guard; calling it *instead* would leave an order
 * reading `AwaitingPayment` with its stock already gone, which is a state
 * nothing else in the system knows how to read.
 *
 * ## Why the age comes from the status history
 *
 * Not `orders.created_at` — the two differ by however long the customer
 * spent filling in the address step, and it is the payment step that starts
 * the clock. `order_status_histories` already records when the order entered
 * `AwaitingPayment`, and `UNIQUE(order_id, new_status)` guarantees exactly
 * one such row. Same precedent as `Order::deliveredAt()` (ADR-0020): read
 * the transition's timestamp from the history rather than denormalising a
 * column that would be a second copy of the same fact.
 *
 * ## Only `AwaitingPayment`
 *
 * A cash-on-delivery order sits at `New` and must never be swept — there is
 * no payment pending for it; it is waiting for staff. That is the whole
 * reason `CheckoutPage` moves a card order to `AwaitingPayment` at all
 * (ADR-0022 decision 1): before that change the two were indistinguishable
 * and this query could not have been written correctly.
 *
 * Null actor: the scheduler acts as the system and holds no permissions, so
 * `TransitionOrderStatus` skips the policy check (ADR-0007). Cancelling an
 * unpaid order takes nothing from anybody, so there is no authorization
 * question to answer.
 *
 * Locks `orders`, then `inventories` — both inside `TransitionOrderStatus`,
 * in the order `reference/write-rules/concurrency.md` declares. One
 * transaction per order rather than one for the batch: a single order whose
 * cancellation fails must not roll back every other order's release.
 */
final class ExpireUnpaidOrders
{
    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * @return int orders cancelled
     */
    public function handle(?Carbon $now = null): int
    {
        $minutes = config('orders.unpaid_ttl_minutes');

        if (! is_numeric($minutes) || (int) $minutes < 1) {
            throw new RuntimeException(
                'config(orders.unpaid_ttl_minutes) must be a positive integer.'
            );
        }

        $cutoff = ($now ?? Carbon::now())->copy()->subMinutes((int) $minutes);

        // "Which orders entered AwaitingPayment before the cutoff" as a
        // subquery over the history table's own columns, the same shape
        // ExpireCarts uses to exclude carts that produced an order. A
        // whereHas closure would express the same thing, but Larastan widens
        // the closure's builder to the base Model and then rejects
        // `new_status` as not a property of it.
        $expiredBefore = OrderStatusHistory::query()
            ->where('new_status', OrderStatus::AwaitingPayment)
            ->where('created_at', '<=', $cutoff)
            ->select('order_id');

        $orders = Order::query()
            ->where('status', OrderStatus::AwaitingPayment)
            ->whereIn('id', $expiredBefore)
            ->get();

        $cancelled = 0;

        foreach ($orders as $order) {
            // Each in its own transaction, inside TransitionOrderStatus. A
            // concurrent webhook that paid this order between the query and
            // here re-reads the locked row, finds it no longer
            // AwaitingPayment, and the transition refuses — which is the
            // correct outcome, not an error worth aborting the sweep for.
            if ($order->fresh()?->status !== OrderStatus::AwaitingPayment) {
                continue;
            }

            $this->transitionOrderStatus->handle(
                $order,
                OrderStatus::Cancelled,
                null,
                'Payment not completed within the allowed window.',
            );

            $cancelled++;
        }

        return $cancelled;
    }
}
