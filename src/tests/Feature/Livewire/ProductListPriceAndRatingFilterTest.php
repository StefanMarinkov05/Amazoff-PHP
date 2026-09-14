<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use Livewire\Livewire;

it('filters products within a price range', function (): void {
    // discount_price explicitly null: the factory's own default is a random
    // value that can land above an overridden regular_price, which the
    // database's own chk_products_discount_below_regular CHECK constraint
    // (ADR-0005) correctly refuses — this test is about regular_price, not
    // discounts, so there is nothing to leave to chance here.
    $cheap = Product::factory()->create(['regular_price' => '10.00', 'discount_price' => null, 'is_available' => true]);
    $mid = Product::factory()->create(['regular_price' => '50.00', 'discount_price' => null, 'is_available' => true]);
    $expensive = Product::factory()->create(['regular_price' => '200.00', 'discount_price' => null, 'is_available' => true]);

    $ids = Livewire::test(ProductList::class)
        ->set('minPrice', '20')
        ->set('maxPrice', '100')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$mid->id])
        ->and($ids)->not->toContain($cheap->id)
        ->and($ids)->not->toContain($expensive->id);
});

it('resets non-numeric price input rather than applying it', function (): void {
    Livewire::test(ProductList::class)
        ->set('minPrice', 'not-a-price')
        ->assertSet('minPrice', null);
});

it('resets a negative price rather than applying it', function (): void {
    Livewire::test(ProductList::class)
        ->set('minPrice', '-50')
        ->assertSet('minPrice', null);
});

it('pushes max price up to match when min is set above it', function (): void {
    Livewire::test(ProductList::class)
        ->set('maxPrice', '20')
        ->set('minPrice', '50')
        ->assertSet('minPrice', '50.00')
        ->assertSet('maxPrice', '50.00');
});

it('does not crash on a price too large for PHP to represent as an int', function (): void {
    Livewire::test(ProductList::class)
        ->set('minPrice', '99999999999999999999999999999999')
        ->assertOk();
});

it('excludes a product whose average rating is below the selected tier', function (): void {
    $lowRated = Product::factory()->create(['is_available' => true]);
    ProductReview::factory()->for($lowRated)->create(['approved' => true, 'rating' => 1]);

    $highRated = Product::factory()->create(['is_available' => true]);
    ProductReview::factory()->for($highRated)->create(['approved' => true, 'rating' => 5]);

    $ids = Livewire::test(ProductList::class)
        ->set('minRating', 4)
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($highRated->id)
        ->and($ids)->not->toContain($lowRated->id);
});

it('always shows a product with no approved reviews, at the strictest tier', function (): void {
    $unrated = Product::factory()->create(['is_available' => true]);
    // An unapproved review must not count toward "has reviews" either —
    // moderation-pending is not the same as rated.
    ProductReview::factory()->for($unrated)->create(['approved' => false, 'rating' => 1]);

    $ids = Livewire::test(ProductList::class)
        ->set('minRating', 4)
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($unrated->id);
});

it('ignores a rating tier not in the allow-list', function (): void {
    Livewire::test(ProductList::class)
        ->set('minRating', 5)
        ->assertSet('minRating', null);
});

it('does not crash on a rating value too large for PHP to represent as an int', function (): void {
    Livewire::test(ProductList::class)
        ->set('minRating', '99999999999999999999999999999999')
        ->assertOk()
        ->assertSet('minRating', null);
});

/*
 * Which variation's price a card shows. Reported live: a product's default
 * variation being out of stock left the card showing the product's own
 * sticker price and an "Out of stock" badge, even when a buyable, discounted
 * sibling sat one click away. ProductList::price() now goes through
 * ResolveCardVariation rather than always reading the product directly.
 */

it('shows a buyable sibling\'s discount when the default variation is out of stock', function (): void {
    // discount_starts_at/ends_at pinned null alongside discount_price:
    // the factory randomises them independently, so overriding only
    // discount_price still leaves a ~30% chance of a real, closed
    // window — windowActive() then correctly suppresses the variation's
    // own discount regardless of its price, which looked like this test
    // was flaky when it was actually finding a real gap in its own setup.
    $product = Product::factory()->create([
        'regular_price' => '100.00', 'discount_price' => null,
        'discount_starts_at' => null, 'discount_ends_at' => null,
        'is_available' => true,
    ]);

    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => true, 'discount_price' => null]);
    Inventory::factory()->create(['product_variation_id' => $default->id, 'current_quantity' => 0, 'reserved_quantity' => 0]);

    $onSale = ProductVariation::factory()->for($product)->create([
        'is_default' => false, 'is_available' => true, 'price' => '100.00', 'discount_price' => '60.00',
    ]);
    Inventory::factory()->create(['product_variation_id' => $onSale->id, 'current_quantity' => 5, 'reserved_quantity' => 0]);

    $price = Livewire::test(ProductList::class)->instance()->price($product->fresh(['productVariations.inventory']));

    expect($price->current)->toBe('60.00')
        ->and($price->onSale)->toBeTrue()
        ->and($price->percent)->toBe(40);
});
