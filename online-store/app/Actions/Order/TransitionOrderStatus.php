<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Inventory\CompleteSale;
use App\Actions\Inventory\ReleaseStock;
use App\Actions\Inventory\RestockReturn;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\IllegalOrderStatusTransitionException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single writer of `orders.status`. The inventory side effect of a
 * transition lives here rather than in a wrapping `CancelOrder`/`ShipOrder`
 * Action (ADR-0011), so a caller that reaches `Cancelled` cannot skip
 * releasing stock by forgetting a wrapper.
 *
 * Legality is `OrderStatus::canTransitionTo()`'s job (ADR-0004). Which actor
 * may attempt a legal move is `OrderPolicy::updateStatus()`'s (routed by
 * target status — cancelling and refunding are administrator moves, per
 * ADR-0011). This class enforces both, writes the §19 history row, applies
 * the inventory effect the target status implies, and dispatches
 * `OrderStatusChanged` after commit.
 *
 * `orders` is contested state: two staff transitioning the same order at once
 * must produce exactly one history row, not two. Locks `orders`, re-reading
 * `status` from the locked row rather than trusting the model passed in —
 * see `explanation/concurrency-and-locking.md`. `$from === $to` is then a
 * clean no-op: a double-submitted transition finds the order already at its
 * target and returns without writing anything, which is safe only because
 * `OrderStatus`'s graph is acyclic
 * (`tests/Unit/Enums/TransitionMatrixTest.php`) — no legitimate second visit
 * to the same status exists to confuse with a race.
 *
 * `UNIQUE(order_id, new_status)` on `order_status_histories` backstops the
 * lock the same way `chk_inventories_reserved_not_above_current` backstops
 * `ReserveStock`: reaching it means the lock failed, and it must surface as
 * a loud `QueryException`, not be caught and treated as the no-op.
 *
 * Lock order: `orders` before `inventories` — the inventory effect below
 * locks each affected line's `inventories` row, sorted by
 * `product_variation_id`, only after the `orders` lock is already held.
 * `reference/write-rules/concurrency.md` has the declared order.
 */
final class TransitionOrderStatus
{
    public function __construct(
        private readonly ReleaseStock $releaseStock,
        private readonly CompleteSale $completeSale,
        private readonly RestockReturn $restockReturn,
    ) {}

    /**
     * @throws IllegalOrderStatusTransitionException
     */
    public function handle(
        Order $order,
        OrderStatus $to,
        ?User $actor,
        ?string $reason = null,
        ?string $note = null,
    ): Order {
        return DB::transaction(function () use ($order, $to, $actor, $reason, $note): Order {
            // lockForUpdate before reading status, not after — a read
            // outside the lock is the same race ReserveStock's own comment
            // warns against, one aggregate over. Re-reading from the locked
            // row is the load-bearing half: evaluating $order->status off
            // the model passed in, hydrated before the lock, protects
            // nothing.
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $from = $locked->status;

            // A self-transition is never "legal" in the matrix (no case
            // lists itself — see TransitionMatrixTest's self-transition
            // test), so it is special-cased here rather than added to the
            // enum. Checked before authorization is decided: a nonsense
            // move is refused regardless of who asked.
            if ($from !== $to && ! $from->canTransitionTo($to)) {
                throw new IllegalOrderStatusTransitionException($locked, $from, $to);
            }

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('updateStatus', [$locked, $to]);
            }

            // The no-op, after authorization: a denied actor must see
            // AuthorizationException even when the move happens to already
            // be done, not a silent success that leaks whether the move
            // would otherwise have been legal.
            //
            // fresh() is typed nullable for the general case (the row could
            // have been deleted since it was loaded), but this order is
            // locked for the length of this transaction and no path in this
            // codebase deletes an order — a null here is not a real
            // possibility, just one the type system can't rule out.
            if ($from === $to) {
                return $locked->fresh(['orderItems', 'orderStatusHistories']) ?? $locked;
            }

            $locked->update(['status' => $to]);

            $locked->orderStatusHistories()->create([
                'user_id' => $actor?->getKey(),
                'previous_status' => $from,
                'new_status' => $to,
                'reason' => $reason,
                'note' => $note,
            ]);

            $this->applyInventoryEffect($locked, $to, $actor, $reason);

            OrderStatusChanged::dispatch($locked, $from, $to, $actor);

            // Same non-null reasoning as the no-op branch above.
            return $locked->fresh(['orderItems', 'orderStatusHistories']) ?? $locked;
        });
    }

    /**
     * The inventory counters that move because of a status change, keyed by
     * the target rather than the source — §20's four release triggers and
     * the sale/return counters both reduce to "what does reaching this
     * status mean for stock," not "what did we come from."
     *
     * Cancellation needs no "was stock actually reserved?" branch: the
     * matrix does not allow `Cancelled` from `Shipped` or `Delivered` (see
     * TransitionMatrixTest), so every reachable cancellation is from a state
     * where stock is reserved and not yet sold. Every line is released
     * unconditionally.
     */
    private function applyInventoryEffect(Order $order, OrderStatus $to, ?User $actor, ?string $reason): void
    {
        $action = match ($to) {
            OrderStatus::Cancelled => $this->releaseStock,
            OrderStatus::Shipped => $this->completeSale,
            OrderStatus::Returned => $this->restockReturn,
            default => null,
        };

        if ($action === null) {
            return;
        }

        // Sorted by the locked resource's own primary key, matching
        // CreateOrder's reasoning: two transitions touching overlapping
        // lines must acquire inventories in the same sequence or they
        // deadlock rather than merely wait.
        /** @var iterable<int, OrderItem> $lines */
        $lines = $order->orderItems()
            ->with(['productVariation' => fn ($query) => $query->withTrashed()])
            ->get()
            ->sortBy('product_variation_id');

        foreach ($lines as $line) {
            /** @var ProductVariation $variation */
            $variation = $line->productVariation;

            $action->handle($variation, $line->quantity, $actor, $reason);
        }
    }
}
