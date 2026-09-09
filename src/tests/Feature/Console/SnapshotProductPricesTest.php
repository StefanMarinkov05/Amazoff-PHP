<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductPriceHistory;

/*
 * products:snapshot-prices — the daily sweep behind the Omnibus prior-price
 * display (ADR-0021). One row per product, unconditionally, so scheduled
 * discount-window transitions are captured without an admin edit.
 */

it('records one observation for every product', function (): void {
    Product::factory()->count(3)->create([
        'regular_price' => '50.00',
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
    ]);

    $this->artisan('products:snapshot-prices')
        ->expectsOutputToContain('Recorded 3 product price observation(s).')
        ->assertSuccessful();

    expect(ProductPriceHistory::count())->toBe(3);
});

it('records the discounted price for a product whose window is open', function (): void {
    $product = Product::factory()->create([
        'regular_price' => '80.00',
        'discount_price' => '60.00',
        'discount_starts_at' => now()->subDay(),
        'discount_ends_at' => now()->addWeek(),
    ]);

    $this->artisan('products:snapshot-prices')->assertSuccessful();

    expect((string) ProductPriceHistory::where('product_id', $product->getKey())->sole()->price)->toBe('60.00');
});

it('writes a fresh row on each run even when nothing changed', function (): void {
    Product::factory()->create(['regular_price' => '30.00', 'discount_price' => null]);

    $this->artisan('products:snapshot-prices')->assertSuccessful();
    $this->artisan('products:snapshot-prices')->assertSuccessful();

    expect(ProductPriceHistory::count())->toBe(2);
});
