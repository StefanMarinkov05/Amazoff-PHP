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
        /*
         * The second half of Stripe's own recommended pairing —
         * "IP allowlisting" alongside signature verification — applied at
         * the application layer via App\Http\Middleware\RestrictStripeWebhookIps
         * rather than at the edge: this deploy's edge is Railway's
         * Railpack/Caddy build, which has no committed config file to add an
         * allow-list to (ADR-0024). $request->ip() is still correct there —
         * bootstrap/app.php's trustProxies(at: '*') resolves the real client
         * IP out of X-Forwarded-For before this ever runs, the same
         * resolution every other IP-scoped check in this app already
         * depends on.
         *
         * Comma-separated CIDRs/IPs, refreshed from
         * https://stripe.com/files/ips/ips_webhooks.txt — see
         * docs/reference/console-commands.md or the middleware's own
         * docblock for the refresh procedure. Unset or empty means the
         * middleware logs once and steps aside rather than rejecting
         * everything: signature verification is the layer that actually
         * authenticates this endpoint, and a stale/missing list must not
         * silently blackhole real payments.
         */
        'webhook_allowed_ips' => env('STRIPE_WEBHOOK_ALLOWED_IPS'),
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
        // Speedy's current REST Web API, confirmed against api.speedy.bg's
        // own published docs 2026-09-06 — the legacy SOAP service (a
        // different host entirely) was fully decommissioned 2024-09-30, so
        // this is not a demo/production split the way Econt's is, just the
        // one live base URL. See docs/explanation/couriers.md, "Speedy —
        // the SOAP service this doc's history might suggest is gone".
        //
        // `?:`, not env()'s own default argument: a present-but-blank
        // SPEEDY_API_URL= in .env (a checked-out placeholder nobody filled
        // in) makes env() return '' rather than null, and env()'s default
        // only fires when the key is absent entirely — the same trap
        // documented on webhook_tolerance below, for a string instead of a
        // number.
        'api_url' => env('SPEEDY_API_URL') ?: 'https://api.speedy.bg/v1',
        'username' => env('SPEEDY_USERNAME'),
        'password' => env('SPEEDY_PASSWORD'),
    ],

];
