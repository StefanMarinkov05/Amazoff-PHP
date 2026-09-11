<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Coupon email pepper
    |--------------------------------------------------------------------------
    |
    | `RedeemCoupon` hashes the order's email (sha256(pepper . lowercased,
    | trimmed email)) instead of storing it, per explanation/gdpr.md,
    | "Coupon limits without storing an email". The pepper lives here, never
    | in the database, so a database dump alone cannot be brute-forced
    | against a dictionary of emails.
    |
    */

    'email_pepper' => env('COUPON_EMAIL_PEPPER'),

];
