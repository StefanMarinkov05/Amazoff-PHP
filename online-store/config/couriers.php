<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Courier gateway
    |--------------------------------------------------------------------------
    |
    | Non-secret knobs for App\Support\Courier\CourierManager. Credentials
    | live in config/services.php, next to Stripe's, per CLAUDE.md's "courier
    | credentials are not in source code".
    |
    */

    'default' => env('COURIER_DEFAULT', 'econt'),

    'timeout' => (int) env('COURIER_TIMEOUT', 10),

    'cache' => [
        // Cities and offices change rarely; a day-long cache is what makes
        // the checkout office picker viable against a real API — see
        // CachedCourierGateway.
        'offices_ttl' => (int) env('COURIER_OFFICES_CACHE_TTL', 86400),
        // A price quote can move with the vendor's own tariff; cached only
        // long enough to survive one checkout session's worth of re-renders.
        'quote_ttl' => (int) env('COURIER_QUOTE_CACHE_TTL', 300),
    ],

    // Fallback parcel weight for a variation with no recorded weight —
    // ResolveVariationMeasurements returns null for one, and a courier quote
    // needs a number regardless.
    'default_parcel_weight_grams' => (int) env('COURIER_DEFAULT_PARCEL_WEIGHT_GRAMS', 500),

];
