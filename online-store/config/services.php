<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // `demo:fetch-images` only — sources real photos for the demo catalogue.
    // Not used by any app-runtime code path.
    'pexels' => [
        'api_key' => env('PEXELS_API_KEY'),
    ],

    /*
     * Stripe. No interface and no connector — ADR-0001 decided this
     * explicitly: one implementation, nothing to swap it for. The SDK's
     * StripeClient is resolved from the container (AppServiceProvider) so a
     * test can bind a fake without an abstraction layer existing in app/.
     *
     * `webhook_secret` is separate from `secret` and is not optional: the
     * webhook route is CSRF-excluded, so the signature is the *only* thing
     * establishing that a request came from Stripe. CLAUDE.md — "one without
     * the other is a free-products vulnerability".
     */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        /*
         * Optional, and temporary by design. Stripe keeps the old signing
         * secret valid for up to 24 hours after a roll and signs each event
         * with every active secret; set this to the outgoing secret for that
         * window so events signed only with the new one are not rejected,
         * then remove it. Left set indefinitely it just widens the accepted
         * set for no benefit.
         */
        'webhook_secret_previous' => env('STRIPE_WEBHOOK_SECRET_PREVIOUS'),
        'currency' => env('STRIPE_CURRENCY', 'eur'),
        // Stripe rejects an event whose timestamp is further from now than
        // this, which is what stops a captured-and-replayed request being
        // valid forever. Seconds.
        /*
         * A blank-but-present STRIPE_WEBHOOK_TOLERANCE (the empty string a
         * checked-out .env.example produces before anyone fills it in) makes
         * (int) cast to 0 — and stripe-php's own check is `$tolerance > 0`,
         * so a tolerance of exactly 0 skips the recency check entirely
         * rather than rejecting everything. That would silently turn "a
         * captured request is valid for 300 seconds" into "valid forever,
         * signature only" with no error anywhere. max() floors it instead
         * of trusting the cast.
         */
        'webhook_tolerance' => max(60, (int) env('STRIPE_WEBHOOK_TOLERANCE', 300)),
    ],

    /*
     * Econt and Speedy — §37 criterion 14, both behind App\Contracts
     * \CourierGateway. `api_url` defaults to Econt's public demo host;
     * Speedy has no equivalent public sandbox, so its credentials are blank
     * until a real account is issued. See docs/explanation/couriers.md.
     */
    'econt' => [
        'api_url' => env('ECONT_API_URL', 'https://demo.econt.com/ee/services/'),
        'username' => env('ECONT_USERNAME'),
        'password' => env('ECONT_PASSWORD'),
    ],

    'speedy' => [
        'api_url' => env('SPEEDY_API_URL'),
        'username' => env('SPEEDY_USERNAME'),
        'password' => env('SPEEDY_PASSWORD'),
    ],

];
