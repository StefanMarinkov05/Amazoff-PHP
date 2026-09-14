<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Livewire\Catalogue\ProductList;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariation;
use Livewire\Livewire;

/*
 * The Omnibus / ЗЗП чл. 6б prior-price line (ADR-0021) shown wherever a
 * reduced price is announced — the product page and the catalogue card.
 */

function saleProductWithHistory(string $regular, string $discount, string $priorLow): Product
{
    $product = Product::factory()->create([
        'name' => 'Discounted Thing',
        'slug' => 'discounted-thing',
        'is_available' => true,
        'regular_price' => $regular,
        'discount_price' => $discount,
        'discount_starts_at' => now()->subDays(2),
        'discount_ends_at' => now()->addWeek(),
    ]);

    $variation = ProductVariation::factory()->create([
        'product_id' => $product->getKey(),
        'price' => null,
        'discount_price' => null,
        'is_available' => true,
    ]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 10,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    ProductPriceHistory::factory()->for($product)->daysAgo(20, $regular)->create();
    ProductPriceHistory::factory()->for($product)->daysAgo(10, $priorLow)->create();

    return $product;
}

it('shows the lowest-30-days line on the product page for a discounted product', function (): void {
    $product = saleProductWithHistory('100.00', '70.00', '85.00');

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->assertSee('Lowest price in the last 30 days: €85.00');
});

it('does not show the line for a product that is not on sale', function (): void {
    $product = Product::factory()->create([
        'slug' => 'full-price-thing',
        'is_available' => true,
        'regular_price' => '100.00',
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
    ]);
    $variation = ProductVariation::factory()->create(['product_id' => $product->getKey(), 'price' => null, 'discount_price' => null, 'is_available' => true]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 5, 'reserved_quantity' => 0, 'sold_quantity' => 0, 'returned_quantity' => 0, 'damaged_quantity' => 0,
    ]);
    ProductPriceHistory::factory()->for($product)->daysAgo(10, '90.00')->create();

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->assertDontSee('Lowest price in the last 30 days');
});

it('shows the line on the catalogue card', function (): void {
    saleProductWithHistory('100.00', '70.00', '82.00');

    Livewire::test(ProductList::class)
        ->assertSee('Lowest in 30 days: €82.00');
});
