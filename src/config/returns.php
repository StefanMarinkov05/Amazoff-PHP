<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Right-of-withdrawal window
    |--------------------------------------------------------------------------
    |
    | The Consumer Rights Directive (2011/83/EU, Arts. 9–15) gives a consumer
    | 14 days from delivery to withdraw. `RequestReturn` refuses a request
    | made after `withdrawal_days` have passed since the order reached
    | `Delivered` (read from `order_status_histories`). ADR-0020.
    |
    | 14 is the statutory figure; it is a config value so counsel can confirm
    | it against the Bulgarian implementation before go-live, and so a
    | promotional longer window is a setting rather than a code change.
    |
    */

    'withdrawal_days' => (int) env('RETURNS_WITHDRAWAL_DAYS', 14),

];
