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

];
