<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Mail\OrderPlaced;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the CRD Art. 8(7) durable-medium confirmation for a **card** order,
 * once its payment has actually arrived. ADR-0022 decision 5.
 *
 * Cash on delivery does not come through here: `CheckoutPage::placeOrder`
 * queues `OrderPlaced` directly for that path, at placement, because a COD
 * contract genuinely is concluded when the order is placed — there is no
 * payment step that can fail and nothing to wait for.
 *
 * A card order is different, and the difference is the bug this listener
 * exists to fix. Until ADR-0022 the email went out at placement for both
 * paths, so a customer who reached Stripe Elements and closed the tab was
 * told "your order" for goods they never paid for. For a card sale the
 * contract concludes at payment, so the confirmation of the concluded
 * contract follows the payment.
 *
 * ## Why a listener rather than a call inside the webhook Action
 *
 * ADR-0011 split side effects by undoability: an effect that shares the
 * transaction's atomicity guarantee belongs inside it, and one that cannot
 * be rolled back regardless — a queued email, by name — belongs on
 * `OrderStatusChanged` after commit. `OrderStatusChanged` is
 * `ShouldDispatchAfterCommit` for exactly this, and carried no listeners
 * until this one; its own docblock called §28's queued emails "a later
 * slice."
 *
 * ## Why it is safe to key on reaching `Paid`
 *
 * `OrderStatus`'s graph is acyclic (`TransitionMatrixTest`), so an order
 * visits `Paid` at most once and this can never send twice.
 * `UNIQUE(order_id, new_status)` on `order_status_histories` backstops that
 * at the database.
 */
final class SendOrderPlacedConfirmation
{
    public function handle(OrderStatusChanged $event): void
    {
        if ($event->to !== OrderStatus::Paid) {
            return;
        }

        // A method whose contract concluded at placement was already
        // confirmed by CheckoutPage, so a second email here would be a
        // duplicate. Asked of the enum rather than written as
        // `=== CashOnDelivery`: a third method must state its own answer,
        // and the two spellings of this check that existed before
        // (`!== Stripe` there, `=== CashOnDelivery` here) would have broken
        // in opposite directions the moment one arrived.
        if ($event->order->payment_method->concludesContractAtPlacement()) {
            return;
        }

        Mail::to($event->order->email)->queue(new OrderPlaced($event->order));
    }
}
