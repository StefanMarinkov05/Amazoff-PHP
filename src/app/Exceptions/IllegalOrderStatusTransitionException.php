<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;
use App\Models\Order;
use RuntimeException;

/**
 * A status change was refused by `OrderStatus::canTransitionTo()`.
 *
 * Also what a double-submitted transition looks like once a genuine race is
 * decided: the loser re-reads under `TransitionOrderStatus`'s lock, finds the
 * order already at the target it was trying to reach, and `$from ===
 * $to` fails this same check — no case lists itself in
 * `OrderStatus::allowedTransitions()`. See ADR-0004 for why legality lives on
 * the enum rather than the Action, and `write-rules/order.md` for the no-op
 * semantics that apply before this is ever thrown.
 *
 * A domain exception rather than a `false` return, per ADR-0007: the message
 * that reaches an employee is the caller's decision, not this class's.
 */
class IllegalOrderStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct(sprintf(
            'Order %s cannot move from %s to %s.',
            $order->serial_number,
            $from->value,
            $to->value,
        ));
    }
}
