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
 * No listeners yet — §28's queued emails are a later slice. This class exists
 * so the first Action that needs the event is not also the first to
 * establish the convention.
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
