<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Livewire\Livewire;

/*
 * SEC-014. `$reviewRating` was `public int`, and the star-rating widget
 * legitimately drives it via `wire:click="$set('reviewRating', N)"` — the
 * one property in this sweep that cannot be `#[Locked]` (that throws
 * CannotUpdateLockedPropertyException on any client set, breaking the
 * feature) but is still hydrated from the raw client value before any
 * component code runs. A number too large for PHP to represent as an int
 * threw an uncaught TypeError at hydration, confirmed live before this fix.
 * Widened to `mixed`; submitReview()'s `integer|min:1|max:5` rule still
 * guards what is actually persisted.
 */

it('does not crash when reviewRating is set to a number too large for PHP to represent as an int', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $variation = ProductVariation::factory()->for($product)->create(['is_available' => true, 'is_default' => true]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 10,
        'reserved_quantity' => 0,
    ]);

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('reviewRating', '99999999999999999999999999999999')
        ->assertOk();
});
