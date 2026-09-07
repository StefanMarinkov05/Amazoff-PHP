<?php

declare(strict_types=1);

use App\Enums\ArticleStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;

/*
 * ADR-0004 puts legality on the enum and says of it: "The matrix is plain
 * data. It is testable without a database, a request, or a user, and the tests
 * read as a table of legal and illegal pairs." Nothing collected that until
 * now, so an accepted ADR claimed a property no test held it to.
 *
 * Four of the twelve enums have a matrix. The other eight classify rather than
 * change — an inventory movement type labels a ledger row, it does not become
 * a different type later — except NewsletterStatus, whose every move is legal,
 * so a matrix would permit everything and assert nothing.
 *
 * ## Why the illegal pairs carry the information
 *
 * A test that only lists legal moves passes against a matrix that permits
 * everything. The assertions worth having are the refusals, and the terminal
 * states most of all: `Refunded => []` is the difference between an order
 * lifecycle and a suggestion.
 *
 * No database, no application. These run in microseconds, which is why the
 * illegal set is enumerated exhaustively rather than sampled — every pair not
 * named legal is asserted illegal, so widening a matrix by accident cannot
 * pass unnoticed.
 */

/**
 * Asserts the whole matrix for one case: every listed target is reachable, and
 * every other case of that enum is not.
 *
 * Exhaustive by construction rather than by a hand-written illegal list, which
 * would go stale the moment a case is added — and going stale silently is the
 * failure this file exists to prevent.
 *
 * @param  list<mixed>  $legal
 */
function assertTransitions(object $from, array $legal): void
{
    expect($from->allowedTransitions())->toEqualCanonicalizing($legal);

    foreach ($from::cases() as $to) {
        expect($from->canTransitionTo($to))->toBe(
            in_array($to, $legal, true),
            sprintf(
                '%s::%s => %s should be %s.',
                $from::class,
                $from->name,
                $to->name,
                in_array($to, $legal, true) ? 'legal' : 'illegal',
            ),
        );
    }
}

/*
 * §18 — the order lifecycle. New => Confirmed exists for cash on delivery,
 * which skips the payment leg; Stripe orders pass through AwaitingPayment.
 */
it('governs the order lifecycle', function (OrderStatus $from, array $legal): void {
    assertTransitions($from, $legal);
})->with([
    'new' => [OrderStatus::New, [OrderStatus::AwaitingPayment, OrderStatus::Confirmed, OrderStatus::Cancelled]],
    'awaiting payment' => [OrderStatus::AwaitingPayment, [OrderStatus::Paid, OrderStatus::Cancelled]],
    'paid' => [OrderStatus::Paid, [OrderStatus::Confirmed, OrderStatus::Cancelled, OrderStatus::Refunded]],
    'confirmed' => [OrderStatus::Confirmed, [OrderStatus::Preparing, OrderStatus::Cancelled, OrderStatus::Refunded]],
    'preparing' => [OrderStatus::Preparing, [OrderStatus::ReadyForShipment, OrderStatus::Cancelled]],
    'ready for shipment' => [OrderStatus::ReadyForShipment, [OrderStatus::Shipped, OrderStatus::Cancelled]],
    'shipped' => [OrderStatus::Shipped, [OrderStatus::Delivered, OrderStatus::Returned]],
    'delivered' => [OrderStatus::Delivered, [OrderStatus::Returned]],
    'cancelled' => [OrderStatus::Cancelled, [OrderStatus::Refunded]],
    'returned' => [OrderStatus::Returned, [OrderStatus::Refunded]],
    'refunded' => [OrderStatus::Refunded, []],
]);

it('refuses to walk an order backwards', function (): void {
    // Named separately from the table because these are the moves a wrong
    // matrix would most plausibly allow, and the table would still pass if
    // someone edited both it and the enum together.
    expect(OrderStatus::Delivered->canTransitionTo(OrderStatus::New))->toBeFalse()
        ->and(OrderStatus::Shipped->canTransitionTo(OrderStatus::Cancelled))->toBeFalse()
        ->and(OrderStatus::Refunded->canTransitionTo(OrderStatus::Paid))->toBeFalse()
        // Cancel after shipped is on the integration edge-case list. The enum
        // is where it is refused, before any policy is consulted.
        ->and(OrderStatus::Delivered->canTransitionTo(OrderStatus::Preparing))->toBeFalse();
});

/*
 * §13 — the payment lifecycle. Guards against out-of-order Stripe webhooks
 * rather than against a user: Failed => Paid is legal because a late
 * payment_intent.succeeded can genuinely arrive after a failure.
 */
it('governs the payment lifecycle', function (PaymentStatus $from, array $legal): void {
    assertTransitions($from, $legal);
})->with([
    'pending' => [PaymentStatus::Pending, [PaymentStatus::Processing, PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled]],
    'processing' => [PaymentStatus::Processing, [PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled]],
    'paid' => [PaymentStatus::Paid, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed]],
    'failed' => [PaymentStatus::Failed, [PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Paid, PaymentStatus::Cancelled]],
    'partially refunded' => [PaymentStatus::PartiallyRefunded, [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded, PaymentStatus::Disputed]],
    // Disputed is reachable only from the states where money arrived, and is
    // not terminal: a dispute won returns to Paid, one lost ends at Refunded.
    'disputed' => [PaymentStatus::Disputed, [PaymentStatus::Paid, PaymentStatus::Refunded]],
    'cancelled' => [PaymentStatus::Cancelled, []],
    'refunded' => [PaymentStatus::Refunded, []],
]);

it('lets a partial refund repeat but never un-refund', function (): void {
    // A second partial refund is legal; the edge case that a second partial
    // exceeding the remainder must be refused is an amount check, not a
    // status one, and belongs to RefundPayment.
    expect(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::PartiallyRefunded))->toBeTrue()
        ->and(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::PartiallyRefunded))->toBeFalse()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Pending))->toBeFalse();
});

/*
 * §15 — shipment status, mapped onto shared cases from two courier
 * vocabularies. The external actor is less trustworthy than the human one.
 */
it('governs the shipment lifecycle', function (ShipmentStatus $from, array $legal): void {
    assertTransitions($from, $legal);
})->with([
    'pending' => [ShipmentStatus::Pending, [ShipmentStatus::Shipped, ShipmentStatus::Cancelled]],
    'shipped' => [ShipmentStatus::Shipped, [ShipmentStatus::InTransit, ShipmentStatus::Delivered, ShipmentStatus::Returned]],
    'in transit' => [ShipmentStatus::InTransit, [ShipmentStatus::Delivered, ShipmentStatus::Returned]],
    'delivered' => [ShipmentStatus::Delivered, [ShipmentStatus::Returned]],
    'returned' => [ShipmentStatus::Returned, []],
    'cancelled' => [ShipmentStatus::Cancelled, []],
]);

/*
 * §22 — deliberately permissive. A content editor picking wrong is visible
 * immediately and cheap to undo, so every state is reachable from every other.
 * That is a decision, not an oversight, which is why it is asserted rather
 * than left untested: someone tightening it should have to change this file.
 */
it('governs the article lifecycle', function (ArticleStatus $from, array $legal): void {
    assertTransitions($from, $legal);
})->with([
    'draft' => [ArticleStatus::Draft, [ArticleStatus::Scheduled, ArticleStatus::Published]],
    'scheduled' => [ArticleStatus::Scheduled, [ArticleStatus::Draft, ArticleStatus::Published]],
    'published' => [ArticleStatus::Published, [ArticleStatus::Draft, ArticleStatus::Archived]],
    'archived' => [ArticleStatus::Archived, [ArticleStatus::Draft, ArticleStatus::Published]],
]);

it('keeps every lifecycle free of a self-transition it did not ask for', function (): void {
    // A status changing to itself writes a §19 history row recording no
    // change. Only PartiallyRefunded wants it, and it wants it for a reason.
    foreach ([OrderStatus::cases(), ShipmentStatus::cases(), ArticleStatus::cases()] as $cases) {
        foreach ($cases as $case) {
            expect($case->canTransitionTo($case))->toBeFalse(
                sprintf('%s::%s should not transition to itself.', $case::class, $case->name),
            );
        }
    }

    foreach (PaymentStatus::cases() as $case) {
        expect($case->canTransitionTo($case))->toBe(
            $case === PaymentStatus::PartiallyRefunded,
            sprintf('%s is the only intended self-transition.', PaymentStatus::PartiallyRefunded->name),
        );
    }
});

/*
 * Order status only. `TransitionOrderStatus` (slice 6) leans on the graph
 * being acyclic for two things a hand-written legal/illegal table above
 * cannot guard: `UNIQUE(order_id, new_status)` on `order_status_histories`
 * is a true invariant only if no order can ever re-enter a status, and
 * `from === to` being read as "this change already happened" (the no-op
 * return) is only safe under the same assumption. A table edited in step
 * with the enum — the exact failure mode this file's own module doc names —
 * would still pass the exhaustive test above even if it introduced a cycle;
 * this is the algorithmic check that does not share that blind spot.
 *
 * Not run for PaymentStatus (PartiallyRefunded is a deliberate self-loop) or
 * ArticleStatus (deliberately permissive, every state reachable from every
 * other per ADR-0004) — a cycle is correct for both, so the property does
 * not apply. Not run for ShipmentStatus because nothing depends on its
 * acyclicity today; add it here first if that changes.
 */
it('keeps the order status graph acyclic', function (): void {
    // Standard white/grey/black DFS cycle detection: 0 = unvisited,
    // 1 = on the current path, 2 = fully explored. Finding a 1 means the
    // path has looped back on itself.
    $state = [];

    $visit = function (OrderStatus $node) use (&$visit, &$state): void {
        $state[$node->value] = 1;

        foreach ($node->allowedTransitions() as $next) {
            expect($state[$next->value] ?? 0)->not->toBe(1, sprintf(
                '%s => %s closes a cycle back through %s. An order must never '.
                'be able to re-enter a status it already left.',
                $node->name,
                $next->name,
                $node->name,
            ));

            if (($state[$next->value] ?? 0) === 0) {
                $visit($next);
            }
        }

        $state[$node->value] = 2;
    };

    foreach (OrderStatus::cases() as $case) {
        if (($state[$case->value] ?? 0) === 0) {
            $visit($case);
        }
    }

    expect($state)->toHaveCount(count(OrderStatus::cases()));
});
