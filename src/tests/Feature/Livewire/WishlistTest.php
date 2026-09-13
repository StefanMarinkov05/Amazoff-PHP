<?php

declare(strict_types=1);

use App\Livewire\Account\Wishlist;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('redirects a guest to login', function (): void {
    $this->get('/wishlist')->assertRedirect('/login');
});

it('lists only the signed-in customer own wishlist', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $mine = Product::factory()->create(['name' => 'My Saved Product']);
    $theirs = Product::factory()->create(['name' => 'Someone Elses Product']);

    WishlistItem::factory()->create(['user_id' => $user->getKey(), 'product_id' => $mine->getKey()]);
    WishlistItem::factory()->create(['user_id' => $other->getKey(), 'product_id' => $theirs->getKey()]);

    Livewire::actingAs($user)
        ->test(Wishlist::class)
        ->assertSee('My Saved Product')
        ->assertDontSee('Someone Elses Product');
});

it('removes an item from the wishlist', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $item = WishlistItem::factory()->create(['user_id' => $user->getKey(), 'product_id' => $product->getKey()]);

    Livewire::actingAs($user)
        ->test(Wishlist::class)
        ->call('remove', $item->getKey());

    expect(WishlistItem::find($item->getKey()))->toBeNull();
});

it('cannot remove another customer wishlist item by guessing its id', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $product = Product::factory()->create();
    $theirs = WishlistItem::factory()->create(['user_id' => $other->getKey(), 'product_id' => $product->getKey()]);

    expect(fn () => Livewire::actingAs($user)->test(Wishlist::class)->call('remove', $theirs->getKey()))
        ->toThrow(ModelNotFoundException::class);

    expect(WishlistItem::find($theirs->getKey()))->not->toBeNull();
});
