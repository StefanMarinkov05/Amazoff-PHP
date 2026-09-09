<?php

declare(strict_types=1);

use App\Enums\ArticleStatus;
use App\Livewire\Home;
use App\Models\Article;
use App\Models\Product;
use App\Models\ProductReview;
use Livewire\Livewire;

/*
 * §4: the home page was Route::redirect('/', '/catalogue') with no page of
 * its own. is_featured already existed on products, already editable in
 * ProductForm — nothing on the storefront ever read it. These tests are
 * about each section pulling the right products, not re-testing
 * ResolveProductPrice or ArticleStatus, which have their own coverage.
 */

/*
 * Assertions below read each section's own #[Computed] property directly
 * rather than assertSee()/assertDontSee() against the rendered page — the
 * page renders all five sections in one response, so a control product
 * correctly excluded from, say, "Featured" can still legitimately appear
 * in "New arrivals" on the same render. Reading the computed property in
 * isolation is what actually proves the exclusion this test is for.
 */

it('shows only featured, available products in the featured section', function (): void {
    cartVariation(product: ['name' => 'Featured One', 'is_featured' => true, 'is_available' => true]);
    cartVariation(product: ['name' => 'Not Featured', 'is_featured' => false, 'is_available' => true]);
    cartVariation(product: ['name' => 'Featured But Unavailable', 'is_featured' => true, 'is_available' => false]);

    $featured = Livewire::test(Home::class)->instance()->featuredProducts();

    expect($featured->pluck('name')->all())
        ->toContain('Featured One')
        ->not->toContain('Not Featured')
        ->not->toContain('Featured But Unavailable');
});

it('shows only products with an active discount window in the on-sale section', function (): void {
    cartVariation(product: [
        'name' => 'Currently On Sale',
        'discount_price' => '10.00',
        'discount_starts_at' => now()->subDay(),
        'discount_ends_at' => now()->addDay(),
    ]);
    cartVariation(product: ['name' => 'No Discount At All', 'discount_price' => null]);
    cartVariation(product: [
        'name' => 'Discount Window Not Started',
        'discount_price' => '10.00',
        'discount_starts_at' => now()->addDay(),
        'discount_ends_at' => now()->addWeek(),
    ]);
    cartVariation(product: [
        'name' => 'Discount Window Ended',
        'discount_price' => '10.00',
        'discount_starts_at' => now()->subWeek(),
        'discount_ends_at' => now()->subDay(),
    ]);

    $discounted = Livewire::test(Home::class)->instance()->discountedProducts();

    expect($discounted->pluck('name')->all())
        ->toContain('Currently On Sale')
        ->not->toContain('No Discount At All')
        ->not->toContain('Discount Window Not Started')
        ->not->toContain('Discount Window Ended');
});

it('excludes an unavailable product from the new-arrivals section', function (): void {
    cartVariation(product: ['name' => 'Available New', 'is_available' => true]);
    cartVariation(product: ['name' => 'Unavailable New', 'is_available' => false]);

    $newArrivals = Livewire::test(Home::class)->instance()->newProducts();

    expect($newArrivals->pluck('name')->all())
        ->toContain('Available New')
        ->not->toContain('Unavailable New');
});

it('shows only products with at least one approved review in the popular section', function (): void {
    $reviewed = cartVariation(product: ['name' => 'Has An Approved Review'])->product;
    ProductReview::factory()->create(['product_id' => $reviewed->getKey(), 'approved' => true]);

    $unreviewedButPending = cartVariation(product: ['name' => 'Only A Pending Review'])->product;
    ProductReview::factory()->create(['product_id' => $unreviewedButPending->getKey(), 'approved' => false]);

    cartVariation(product: ['name' => 'No Reviews At All']);

    $popular = Livewire::test(Home::class)->instance()->popularProducts();

    expect($popular->pluck('name')->all())
        ->toContain('Has An Approved Review')
        ->not->toContain('Only A Pending Review')
        ->not->toContain('No Reviews At All');
});

it('orders the popular section by approved review count, most first', function (): void {
    $mostReviewed = cartVariation(product: ['name' => 'Most Reviewed'])->product;
    ProductReview::factory()->count(3)->create(['product_id' => $mostReviewed->getKey(), 'approved' => true]);

    $leastReviewed = cartVariation(product: ['name' => 'Least Reviewed'])->product;
    ProductReview::factory()->create(['product_id' => $leastReviewed->getKey(), 'approved' => true]);

    $popular = Livewire::test(Home::class)->instance()->popularProducts();

    expect($popular->first()->name)->toBe('Most Reviewed')
        ->and($popular->last()->name)->toBe('Least Reviewed');
});

it('shows only visible articles in the latest-articles section', function (): void {
    Article::factory()->create([
        'title' => 'A Published Visible Article',
        'status' => ArticleStatus::Published,
        'published_at' => now()->subDay(),
    ]);
    Article::factory()->create([
        'title' => 'A Draft Article',
        'status' => ArticleStatus::Draft,
        'published_at' => null,
    ]);
    Article::factory()->create([
        'title' => 'A Future-Dated Article',
        'status' => ArticleStatus::Published,
        'published_at' => now()->addWeek(),
    ]);

    Livewire::test(Home::class)
        ->assertSee('A Published Visible Article')
        ->assertDontSee('A Draft Article')
        ->assertDontSee('A Future-Dated Article');
});

it('renders with nothing to show in any section, rather than crashing', function (): void {
    Livewire::test(Home::class)->assertOk();
});
