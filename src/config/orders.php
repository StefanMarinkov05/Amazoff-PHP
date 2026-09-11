<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Unpaid-order lifetime
    |--------------------------------------------------------------------------
    |
    | How long a card order may sit in `AwaitingPayment` before the scheduled
    | `orders:expire-unpaid` sweep cancels it and releases its reserved stock
    | (ADR-0022). Measured from the `order_status_histories` row for the
    | `AwaitingPayment` transition, not from `orders.created_at` — the two
    | differ by however long the customer spent on the address step.
    |
    | Ten minutes so that one timer governs both this and the checkout-state
    | timeout: "a checkout sitting unfinished reverts to cart state" and
    | "the stock it was holding goes back" are the same deadline, and keeping
    | them as two numbers would be two facts to hold in sync.
    |
    | The known cost is that ten minutes is shorter than the slowest genuine
    | 3-D Secure payments — a bank app on a second device, a card reader
    | being hunted for. That order is cancelled mid-payment and the late
    | `payment_intent.succeeded` lands against a cancelled order, which staff
    | see in the panel as a paid payment on a cancelled order and refund.
    | It is a config value so raising it needs no code change if that turns
    | out to be common.
    |
    */

    'unpaid_ttl_minutes' => (int) env('ORDERS_UNPAID_TTL_MINUTES', 10),

];
