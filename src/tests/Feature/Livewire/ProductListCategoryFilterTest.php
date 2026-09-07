<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use App\Models\Product;
use App\Models\ProductCategory;
use Livewire\Livewire;

/*
 * The filter reads the category by slug from the URL, not id — a
 * catalogue link is meant to be shareable and readable, and a selected
 * parent has to include every descendant's products, not only its own
 * (real seeded data: Garden holds 3 products directly, on top of Mowers'
 * and Watering's — a parent is not purely a grouping node).
 */

it('shows a leaf category\'s own products when its slug is selected', function (): void {
    $category = ProductCategory::factory()->create();
    $matching = Product::factory()->count(2)->create(['product_category_id' => $category->id, 'is_available' => true]);
    $other = Product::factory()->create(['is_available' => true]);

    $ids = Livewire::test(ProductList::class)
        ->set('categorySlug', $category->slug)
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toEqualCanonicalizing($matching->pluck('id')->all())
        ->and($ids)->not->toContain($other->id);
});

it('selecting a parent category includes products on its children, and its own', function (): void {
    $parent = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->childOf($parent)->create();

    $onParent = Product::factory()->create(['product_category_id' => $parent->id, 'is_available' => true]);
    $onChild = Product::factory()->create(['product_category_id' => $child->id, 'is_available' => true]);
    $elsewhere = Product::factory()->create(['is_available' => true]);

    $ids = Livewire::test(ProductList::class)
        ->set('categorySlug', $parent->slug)
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toEqualCanonicalizing([$onParent->id, $onChild->id])
        ->and($ids)->not->toContain($elsewhere->id);
});

it('selecting a parent reaches a grandchild\'s products too, not only direct children', function (): void {
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    $product = Product::factory()->create(['product_category_id' => $leaf->id, 'is_available' => true]);

    $ids = Livewire::test(ProductList::class)
        ->set('categorySlug', $top->slug)
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($product->id);
});

it('a slug that matches no category falls back to the unfiltered catalogue rather than erroring', function (): void {
    $product = Product::factory()->create(['is_available' => true]);

    Livewire::test(ProductList::class)
        ->set('categorySlug', 'does-not-exist')
        ->assertOk()
        ->assertViewHas('products', fn ($products) => $products->pluck('id')->contains($product->id));
});

/*
 * `categorySlug` only ever reaches a parameterised Eloquent where() —
 * these prove that structurally, not just assert "it didn't crash":
 * an injection-shaped value that matched something would leak data, and a
 * huge string past a column's varchar bound would 500 rather than fail
 * cleanly, if the value ever reached raw SQL or an unguarded query.
 */
it('treats a SQL-injection-shaped slug as no match, not as SQL', function (): void {
    Product::factory()->count(3)->create(['is_available' => true]);

    Livewire::test(ProductList::class)
        ->set('categorySlug', "' OR '1'='1")
        ->assertOk()
        ->assertViewHas('products', fn ($products) => $products->count() === 3);
});

it('does not crash on an XSS-shaped slug, and never reflects it unescaped', function (): void {
    Livewire::test(ProductList::class)
        ->set('categorySlug', '<script>alert(1)</script>')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('does not crash on a slug far longer than the column allows', function (): void {
    Livewire::test(ProductList::class)
        ->set('categorySlug', str_repeat('a', 5000))
        ->assertOk();
});
