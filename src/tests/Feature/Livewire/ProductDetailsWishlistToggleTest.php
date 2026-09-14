<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\WishlistItem;
use Livewire\Livewire;

/*
 * The wishlist table (WishlistItem) already existed — ForceDeleteProduct
 * already refuses to erase a wishlisted product — but nothing wrote to it.
 * This is that missing wiring on the product detail page.
 */

function wishlistableProduct(): Product
{
    $product = Product::factory()->create(['is_available' => true]);
    $variation = ProductVariation::factory()->for($product)->create([
        'is_available' => true,
        'is_default' => true,
    ]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 10,
        'reserved_quantity' => 0,
    ]);

    return $product;
}

it('shows not wishlisted for a guest', function (): void {
    $product = wishlistableProduct();

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->assertSet('isWishlisted', false);
});

it('adds the product to the wishlist on first toggle', function (): void {
    $product = wishlistableProduct();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->call('toggleWishlist')
        ->assertSet('isWishlisted', true);

    expect(WishlistItem::query()
        ->where('user_id', $user->getKey())
        ->where('product_id', $product->getKey())
        ->exists())->toBeTrue();
});

it('removes the product from the wishlist on a second toggle', function (): void {
    $product = wishlistableProduct();
    $user = User::factory()->create();
    WishlistItem::factory()->create(['user_id' => $user->getKey(), 'product_id' => $product->getKey()]);

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->assertSet('isWishlisted', true)
        ->call('toggleWishlist')
        ->assertSet('isWishlisted', false);

    expect(WishlistItem::query()
        ->where('user_id', $user->getKey())
        ->where('product_id', $product->getKey())
        ->exists())->toBeFalse();
});

it('redirects a guest to login rather than crashing on toggle', function (): void {
    $product = wishlistableProduct();

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->call('toggleWishlist')
        ->assertRedirect('/login');

    expect(WishlistItem::query()->where('product_id', $product->getKey())->exists())->toBeFalse();
});
