<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once by `TransitionOrderStatus` after a status write commits.
 *
 * `ShouldDispatchAfterCommit` is a property of this class rather than of the
 * dispatch site, per ADR-0007 — it holds no matter how deeply
 * `TransitionOrderStatus` is nested inside a caller's own transaction. An
 * event dispatched inside the transaction would be delivered even if the
 * transaction rolled back; §28 queues the resulting emails, so the
 * observable failure would be a customer notified about a status change that
 * never happened.
 *
 * `App\Listeners\SendOrderPlacedConfirmation` is the one listener, bound by
 * Laravel's auto-discovery (no explicit `EventServiceProvider` mapping) —
 * confirmed live via `artisan event:list`. It queues the CRD Art. 8(7)
 * confirmation for a **card** order once this event's `to` reaches `Paid`;
 * see its own docblock for why cash-on-delivery doesn't go through here.
 */
class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly ?User $actor,
    ) {}
}
