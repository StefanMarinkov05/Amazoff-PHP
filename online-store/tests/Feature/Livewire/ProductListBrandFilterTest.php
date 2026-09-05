<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use App\Models\Brand;
use App\Models\Product;
use Livewire\Livewire;

/*
 * The same incident class as ProductDetailsQuantityTest and
 * ProductDetailsVariationIdTest, a third property: `?brandId=` was `?int`
 * typed. A number too large for PHP to represent as an int decodes to a
 * float before Livewire's #[Url] hydration assigns it, and `?int` refuses
 * the float assignment the same way it refused the oversized string for
 * $quantity and $variationId — an unhandled 500 from a crafted URL,
 * confirmed live (curl against the running app) before this fix.
 *
 * docs/how-to/test-for-input-crashes.md's playbook is per property, not per
 * component — this is the third property on the third component this
 * project has found the same class of bug on, which is why the coverage
 * index (reference/testing/tested-inputs.md) exists: reviewing $categorySlug clean
 * on this same component did not mean $brandId was.
 */

it('does not crash on a brand id too large for PHP to represent as an int', function (): void {
    Product::factory()->create(['is_available' => true]);

    Livewire::withQueryParams(['brandId' => '99999999999999999999999999999999'])
        ->test(ProductList::class)
        ->assertOk();
});

it('does not crash on a non-numeric brand id', function (): void {
    Product::factory()->create(['is_available' => true]);

    Livewire::withQueryParams(['brandId' => 'not-a-number'])
        ->test(ProductList::class)
        ->assertOk();
});

it('does not crash on an XSS-shaped brand id and does not reflect it unescaped', function (): void {
    Product::factory()->create(['is_available' => true]);

    Livewire::withQueryParams(['brandId' => '<script>alert(1)</script>'])
        ->test(ProductList::class)
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('finds no products for a well-formed but non-existent brand id, without crashing', function (): void {
    // 999999 is a valid ?int — safeBrandId() normalises it to the int and
    // the where('brand_id', ...) correctly excludes everything, the same as
    // a real id nothing matches. This does not crash, which is the property
    // under test; it is categorySlug's "no such slug" case, not "malformed
    // input falls back to unfiltered" — that fallback is for the values
    // safeBrandId() itself rejects (tested above: overflow, non-numeric,
    // XSS-shaped all normalise to null and apply no filter at all).
    Product::factory()->create(['is_available' => true]);

    $ids = Livewire::withQueryParams(['brandId' => '999999'])
        ->test(ProductList::class)
        ->assertOk()
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([]);
});

it('applies no brand filter for a malformed id, rather than matching nothing', function (): void {
    // The actual fallback safeBrandId() provides: non-numeric input becomes
    // null, and null means "no filter", not "filter to nothing" — same
    // distinction ProductDetailsVariationIdTest draws for $variationId.
    $product = Product::factory()->create(['is_available' => true]);

    $ids = Livewire::withQueryParams(['brandId' => 'not-a-number'])
        ->test(ProductList::class)
        ->assertOk()
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($product->id);
});

it('filters correctly with a real brand id, proving the fix did not break the filter', function (): void {
    $brand = Brand::factory()->create();
    $matching = Product::factory()->create(['is_available' => true, 'brand_id' => $brand->id]);
    $other = Product::factory()->create(['is_available' => true]);

    $ids = Livewire::withQueryParams(['brandId' => (string) $brand->id])
        ->test(ProductList::class)
        ->assertOk()
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($matching->id)
        ->and($ids)->not->toContain($other->id);
});
