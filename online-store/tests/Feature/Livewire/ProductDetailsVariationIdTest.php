<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Sequence;

/*
 * The same incident class as ProductDetailsQuantityTest, a different
 * property: `?v=` (variationId, #[Url]-bound) was `?int`-typed. A number
 * too large for PHP to represent cleanly decodes to a float before
 * Livewire's hydration assigns it, and `?int` refuses a float assignment
 * the same way it refused the oversized string for $quantity — confirmed
 * live (curl against a real product page) before this fix, not assumed
 * from the type alone.
 *
 * $this->get() against the real route, not Livewire::test()->set(): the
 * crash happens during #[Url] hydration, which runs before mount() as part
 * of booting the component for a real request — a property set() after the
 * component already exists does not reproduce it. docs/how-to/
 * test-for-input-crashes.md has the general lesson: this playbook is per
 * property — $categorySlug clean on ProductList did not mean $variationId
 * was clean on a different component entirely.
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

    $this->get("/products/{$product->slug}?v=99999999999999999999999999999999")
        ->assertOk();
});

it('does not crash on a non-numeric variation id', function (): void {
    $product = makeProductWithVariations();

    $this->get("/products/{$product->slug}?v=not-a-number")
        ->assertOk();
});

it('falls back to the default variation when the id in the URL does not match anything real', function (): void {
    $product = makeProductWithVariations();
    $default = $product->productVariations()->where('is_default', true)->sole();

    $this->get("/products/{$product->slug}?v=999999")
        ->assertOk()
        ->assertSeeText($default->sku);
});
