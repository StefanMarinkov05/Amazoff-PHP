<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Livewire\Livewire;

it('reports no wishlisted products for a guest', function (): void {
    Product::factory()->create(['is_available' => true]);

    Livewire::test(ProductList::class)
        ->assertSet('wishlistedProductIds', []);
});

it('toggles a product onto the wishlist from the grid', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProductList::class)
        ->call('toggleWishlist', $product->getKey())
        ->assertSet('wishlistedProductIds', [$product->getKey()]);

    expect(WishlistItem::query()
        ->where('user_id', $user->getKey())
        ->where('product_id', $product->getKey())
        ->exists())->toBeTrue();
});

it('toggles a product off the wishlist from the grid', function (): void {
    $product = Product::factory()->create(['is_available' => true]);
    $user = User::factory()->create();
    WishlistItem::factory()->create(['user_id' => $user->getKey(), 'product_id' => $product->getKey()]);

    Livewire::actingAs($user)
        ->test(ProductList::class)
        ->call('toggleWishlist', $product->getKey())
        ->assertSet('wishlistedProductIds', []);

    expect(WishlistItem::query()->where('product_id', $product->getKey())->exists())->toBeFalse();
});

it('redirects a guest to login rather than crashing on toggle', function (): void {
    $product = Product::factory()->create(['is_available' => true]);

    Livewire::test(ProductList::class)
        ->call('toggleWishlist', $product->getKey())
        ->assertRedirect('/login');

    expect(WishlistItem::query()->where('product_id', $product->getKey())->exists())->toBeFalse();
});
