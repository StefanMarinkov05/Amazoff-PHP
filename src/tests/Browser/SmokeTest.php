<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\Product;
use App\Models\User;

/*
 * Smoke matrix — every reachable page loads without a server error and
 * without a JavaScript console error. Cheap, and it catches "the whole page
 * is broken" that a Feature test asserting one string on a 500 would miss
 * (a 500 whose body still contains the asserted word passes a Feature test;
 * it fails here).
 *
 * Pages are visited in batches (`visit([...])`) rather than one test each:
 * Pest creates and tears down a browser context per `it()`, ~15s of fixed
 * overhead, so 25 separate tests would be ~7 minutes where three batched
 * ones are under one. assertNoSmoke() = assertNoConsoleLogs() +
 * assertNoJavaScriptErrors(), fanned out to every page in the batch.
 *
 * The known non-failures the manual passes recorded (sub-24px tap targets,
 * the catalogue grid stuck at 3 columns) are layout notes, not console
 * errors, so they do not surface here — ResponsiveTest carries those.
 */

it('loads every public storefront page cleanly', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $article = Article::factory()->create(['published_at' => now()->subDay()]);

    visit([
        '/',
        '/catalogue',
        "/products/{$product->slug}",
        '/cart',
        '/checkout',
        '/orders/track',
        '/journal',
        "/journal/{$article->slug}",
        '/contact',
        '/login',
        '/register',
        '/password/reset',
        '/about',
        '/faq',
        '/delivery',
        '/payment-information',
        '/terms',
        '/privacy',
        '/cookies',
    ])->assertNoSmoke();
});

it('loads the signed-in account pages cleanly', function (): void {
    $this->actingAs(User::factory()->create());

    visit([
        '/account/orders',
        '/account/profile',
        '/account/password',
        '/account/addresses',
        '/wishlist',
    ])->assertNoSmoke();
});

it('loads the admin panel dashboard cleanly for an administrator', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    visit('/admin')->assertNoSmoke();
});
