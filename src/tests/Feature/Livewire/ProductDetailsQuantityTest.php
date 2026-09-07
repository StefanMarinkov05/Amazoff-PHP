<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Livewire\Livewire;

/*
 * A malformed `quantity` reaching this component is user input in exactly
 * the sense ADR-0014 already treats #[Url] properties as: wire:model sends
 * whatever the client sends, with no server-side ceiling from the number
 * input's own min/max attributes. `$quantity` was `int`-typed, and Livewire
 * assigns the raw request value to a typed property before any of the
 * component's own code runs — a numeric string PHP cannot represent as an
 * int threw an uncaught TypeError right there, never reaching
 * updatedQuantity()'s clamp.
 */

function makeAvailableVariation(int $currentQuantity = 50, int $minOrderQuantity = 1): array
{
    $product = Product::factory()->create([
        'is_available' => true,
        'min_order_quantity' => $minOrderQuantity,
    ]);
    $variation = ProductVariation::factory()->for($product)->create([
        'is_available' => true,
        'is_default' => true,
    ]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $currentQuantity,
        'reserved_quantity' => 0,
    ]);

    return [$product, $variation];
}

it('does not crash on a number too large for PHP to represent as an int', function (): void {
    [$product] = makeAvailableVariation();

    // 34 digits — past PHP_INT_MAX, the exact case that threw a raw
    // TypeError before quantity was widened past a strict int type.
    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', '99999999999999999999999999999999')
        ->assertOk();
});

it('resets non-numeric quantity input to the minimum rather than keeping it', function (): void {
    [$product] = makeAvailableVariation(minOrderQuantity: 3);

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', 'not-a-number')
        ->assertSet('quantity', 3);
});

it('rejects a decimal quantity back to the minimum', function (): void {
    [$product] = makeAvailableVariation(minOrderQuantity: 2);

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', '5.5')
        ->assertSet('quantity', 2);
});

it('clamps a quantity within PHP int range but past available stock to the stock ceiling', function (): void {
    [$product] = makeAvailableVariation(currentQuantity: 10);

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', 5_000_000_000)
        ->assertSet('quantity', 10);
});

it('floors a quantity below the minimum order quantity', function (): void {
    [$product] = makeAvailableVariation(minOrderQuantity: 4);

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', 1)
        ->assertSet('quantity', 4);
});

it('adds to cart successfully with a normal, valid quantity', function (): void {
    [$product] = makeAvailableVariation();

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', 3)
        ->call('addToCart')
        ->assertHasNoErrors();
});

it('warns and disables add-to-cart when stock is below the minimum order quantity', function (): void {
    [$product] = makeAvailableVariation(currentQuantity: 2, minOrderQuantity: 5);

    $component = Livewire::test(ProductDetails::class, ['product' => $product])
        ->assertOk();

    expect($component->html())
        ->toContain('Only 2 in stock')
        ->toContain('below the minimum order of 5')
        ->toContain('Not enough stock');
});

it('does not warn when stock meets or exceeds the minimum order quantity', function (): void {
    [$product] = makeAvailableVariation(currentQuantity: 10, minOrderQuantity: 5);

    $component = Livewire::test(ProductDetails::class, ['product' => $product]);

    expect($component->html())->not->toContain('below the minimum order');
});

it('still refuses the add cleanly server-side even if the disabled button is bypassed', function (): void {
    [$product] = makeAvailableVariation(currentQuantity: 2, minOrderQuantity: 5);

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->set('quantity', 5)
        ->call('addToCart')
        ->assertHasErrors('cart');
});
