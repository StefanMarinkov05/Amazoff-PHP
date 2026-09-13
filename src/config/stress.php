<?php

declare(strict_types=1);

// Tuning for the two seeders under database/seeders/Stress/ only. Never
// read by app-runtime code — a dedicated file rather than an addition to
// services.php because these are seeder parameters, not third-party
// credentials. env() must not be called directly inside a seeder (it
// returns null once config is cached), so this is the one place both
// StressSeeder and CatalogueStressSeeder are allowed to reach for it.
return [
    'order_count' => env('STRESS_SEED_COUNT'),
    'catalogue_count' => env('CATALOGUE_STRESS_COUNT'),
    'deep_catalogue_count' => env('DEEP_STRESS_COUNT'),
    'deep_min_variations' => env('DEEP_STRESS_MIN_VARIATIONS'),
    'deep_max_variations' => env('DEEP_STRESS_MAX_VARIATIONS'),
    'deep_images_per_product' => env('DEEP_STRESS_IMAGES_PER_PRODUCT'),
];
