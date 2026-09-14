<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Guest cart lifetime
    |--------------------------------------------------------------------------
    |
    | How long a guest's cart survives without being touched, in hours.
    | `TouchCartExpiry` stamps `carts.expires_at` this far ahead on every
    | write, and the scheduled `carts:expire` command deletes what has passed.
    |
    | Guests only. A registered customer's cart never expires — it is theirs,
    | it is reachable from any device, and deleting it silently loses
    | something they can see. What expires for a logged-in customer is the
    | checkout stage, not the cart, and that is a separate mechanism.
    |
    | One day rather than the month an earlier note suggested: a guest cart is
    | anonymous, keyed only to a session, and unreachable the moment that
    | session is gone. Keeping it a month accumulates rows nobody can ever
    | return to.
    |
    */

    'guest_ttl_hours' => (int) env('CART_GUEST_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Cart size caps
    |--------------------------------------------------------------------------
    |
    | Two different abuse shapes, so two different limits — capping one
    | leaves the other wide open.
    |
    | `max_lines` bounds how many *distinct* variations a cart may hold. The
    | cart page, the checkout summary and the order-confirmation email all
    | render one row per line and `CreateOrder` writes one `order_items` row
    | and takes one `inventories` lock per line, so an unbounded cart makes
    | every one of those arbitrarily expensive for one visitor to trigger.
    |
    | `max_units` bounds the total quantity across all lines. This is the
    | one that matters for stock: `CreateOrder` reserves every unit at
    | checkout, so without it a single session can hold an entire product's
    | inventory hostage for the length of the unpaid-order TTL (ADR-0022)
    | without paying anything. `min_order_quantity` and available stock are
    | per-line checks and neither bounds the cart as a whole.
    |
    | Both are deliberately generous — a real basket does not approach
    | either — because the purpose is to bound the worst case, not to
    | second-guess a legitimate bulk order. Raise them in env if a genuine
    | customer ever hits one.
    |
    */

    'max_lines' => (int) env('CART_MAX_LINES', 50),

    'max_units' => (int) env('CART_MAX_UNITS', 200),

];
