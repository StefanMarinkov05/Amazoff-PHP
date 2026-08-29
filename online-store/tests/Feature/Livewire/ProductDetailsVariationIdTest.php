<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;

/*
 * The same incident class as ProductDetailsQuantityTest, a different
 * property: `?v=` (variationId, #[Url]-bound) was `?int`-typed. A number
 * too large for PHP to represent cleanly decodes to a float before
 * Livewire's hydration assigns it, and `?int` refuses a float assignment
 * the same way it refused the oversized string for $quantity — confirmed
 * live (curl against a real product page) before this fix, not assumed
 * from the type alone.
 *
 * Livewire::withQueryParams(), not $this->get(): the crash happens during
 * #[Url] hydration, which withQueryParams() simulates directly against the
 * component under test — a property set() after the component already
 * exists does not reproduce it, which is why this needs something that
 * hydrates before mount(), not after. $this->get() against the real route
 * was tried first and does reproduce the crash locally, but it also renders
 * the full page layout (app.blade.php's @vite() call), which needs a built
 * public/build/manifest.json — present locally (the dev server), absent in
 * CI's `test` shards by design (use-ci.md: no npm/build step, because
 * nothing under tests/Feature was thought to touch a compiled asset before
 * this file). That combination passed on every local run and failed live
 * in CI on this exact test, for a reason with nothing to do with the fix
 * itself — caught from the PR's own CI run, not from local testing alone.
 *
 * docs/how-to/test-for-input-crashes.md has the general per-property
 * lesson this test file is itself an instance of: $categorySlug clean on
 * ProductList did not mean $variationId was clean on a different component.
 */

function makeProductWithVariations(int $variationCount = 1): Product
{
    $product = Product::factory()->create(['is_available' => true]);

    ProductVariation::factory()
        ->for($product)
        ->count($variationCount)
        ->sequence(fn (Sequence $sequence) => ['is_default' => $sequence->index === 0])
        ->create(['is_available' => true])
        ->each(function (ProductVariation $variation): void {
            Inventory::factory()->create([
                'product_variation_id' => $variation->getKey(),
                'current_quantity' => 10,
                'reserved_quantity' => 0,
            ]);
        });

    return $product;
}

it('does not crash on a variation id too large for PHP to represent as an int', function (): void {
    $product = makeProductWithVariations();

    Livewire::withQueryParams(['v' => '99999999999999999999999999999999'])
        ->test(ProductDetails::class, ['product' => $product])
        ->assertOk();
});

it('does not crash on a non-numeric variation id', function (): void {
    $product = makeProductWithVariations();

    Livewire::withQueryParams(['v' => 'not-a-number'])
        ->test(ProductDetails::class, ['product' => $product])
        ->assertOk();
});

it('falls back to the default variation when the id in the URL does not match anything real', function (): void {
    $product = makeProductWithVariations();
    $default = $product->productVariations()->where('is_default', true)->sole();

    Livewire::withQueryParams(['v' => '999999'])
        ->test(ProductDetails::class, ['product' => $product])
        ->assertOk()
        ->assertSeeText($default->sku);
});
