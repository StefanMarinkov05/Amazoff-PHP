<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * SEC-014. `$productId` and `$imageIndex` were `?int`/`int`, not
 * `#[Locked]`, despite both being set only server-side (mount(), and
 * setImage()/nextImage()/previousImage() respectively — confirmed no
 * blade `$set` targets either). A client `$set(..., <34-digit>)` on either
 * threw an uncaught TypeError at hydration — confirmed live before this
 * fix. Same incident class as ProductDetailsVariationIdTest's own
 * $variationId case, a different pair of properties.
 */

function lockedIdsProduct(): Product
{
    $product = Product::factory()->create(['is_available' => true]);
    $variation = ProductVariation::factory()->for($product)->create(['is_available' => true, 'is_default' => true]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 10,
        'reserved_quantity' => 0,
    ]);

    return $product;
}

it('locks productId against client tampering', function (): void {
    $product = lockedIdsProduct();

    expect(fn () => Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('productId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not crash on a client-set productId too large for PHP to represent as an int', function (): void {
    $product = lockedIdsProduct();

    expect(fn () => Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('productId', '99999999999999999999999999999999'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->not->toThrow(TypeError::class);
});

it('locks imageIndex against client tampering', function (): void {
    $product = lockedIdsProduct();

    expect(fn () => Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('imageIndex', 5))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not crash on a client-set imageIndex too large for PHP to represent as an int', function (): void {
    $product = lockedIdsProduct();

    expect(fn () => Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('imageIndex', '99999999999999999999999999999999'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->not->toThrow(TypeError::class);
});
