<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Support\Resolvers\ResolveCardVariation;

/*
 * Which variation a catalogue card represents. Reported live: a card whose
 * default variation was out of stock kept showing "Out of stock" and the
 * product's own sticker price, while a sibling variation sitting one click
 * away was buyable and on sale — nothing on the card told a shopper that.
 */

/** Attaches a stock row directly, since the test builds variations by hand rather than through AddProductVariation. */
function stockFor(ProductVariation $variation, int $current, int $reserved = 0): void
{
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $current,
        'reserved_quantity' => $reserved,
    ]);
}

it('picks the default variation when it is buyable', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => true]);
    stockFor($default, 5);

    // A discounted sibling exists but must not win while the default is
    // itself buyable — "prefer the deepest discount" only applies once the
    // default has been ruled out.
    $sibling = ProductVariation::factory()->for($product)->create(['is_default' => false, 'is_available' => true, 'discount_price' => 1]);
    stockFor($sibling, 5);

    expect(ResolveCardVariation::current($product->fresh(['productVariations.inventory']))->getKey())
        ->toBe($default->getKey());
});

it('falls back to a buyable sibling when the default is out of stock', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => true]);
    stockFor($default, 0);

    $buyable = ProductVariation::factory()->for($product)->create(['is_default' => false, 'is_available' => true]);
    stockFor($buyable, 3);

    expect(ResolveCardVariation::current($product->fresh(['productVariations.inventory']))->getKey())
        ->toBe($buyable->getKey());
});

it('falls back to a buyable sibling when the default is deactivated', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => false]);
    stockFor($default, 5);

    $buyable = ProductVariation::factory()->for($product)->create(['is_default' => false, 'is_available' => true]);
    stockFor($buyable, 3);

    expect(ResolveCardVariation::current($product->fresh(['productVariations.inventory']))->getKey())
        ->toBe($buyable->getKey());
});

it('prefers the buyable variation with the deepest discount once the default is ruled out', function (): void {
    // discount_starts_at/ends_at pinned null alongside discount_price —
    // see ProductListPriceAndRatingFilterTest's own note on the same gap.
    $product = Product::factory()->create([
        'regular_price' => '100.00', 'discount_price' => null,
        'discount_starts_at' => null, 'discount_ends_at' => null,
    ]);
    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => true]);
    stockFor($default, 0);

    $smallDiscount = ProductVariation::factory()->for($product)->create([
        'is_default' => false, 'is_available' => true, 'price' => '100.00', 'discount_price' => '90.00',
    ]);
    stockFor($smallDiscount, 3);

    $bigDiscount = ProductVariation::factory()->for($product)->create([
        'is_default' => false, 'is_available' => true, 'price' => '100.00', 'discount_price' => '40.00',
    ]);
    stockFor($bigDiscount, 3);

    // discount_price pinned null, not left to the factory's own default: it
    // computes a random discount from its own randomly-generated price
    // *before* the 'price' override above replaces it, which can leave a
    // discount above the overridden price — chk_..._discount_below_price
    // correctly refuses that, intermittently, depending on the random draw.
    $noDiscount = ProductVariation::factory()->for($product)->create([
        'is_default' => false, 'is_available' => true, 'price' => '100.00', 'discount_price' => null,
    ]);
    stockFor($noDiscount, 3);

    expect(ResolveCardVariation::current($product->fresh(['productVariations.inventory']))->getKey())
        ->toBe($bigDiscount->getKey());
});

it('does not consider reserved stock buyable', function (): void {
    // available() = current - reserved. A unit held for someone mid-checkout
    // is not one this shopper can buy — the same guard availableStock() uses.
    $product = Product::factory()->create();
    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => true]);
    stockFor($default, current: 5, reserved: 5);

    $buyable = ProductVariation::factory()->for($product)->create(['is_default' => false, 'is_available' => true]);
    stockFor($buyable, 3);

    expect(ResolveCardVariation::current($product->fresh(['productVariations.inventory']))->getKey())
        ->toBe($buyable->getKey());
});

it('falls back to the default variation when nothing is buyable', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariation::factory()->for($product)->create(['is_default' => true, 'is_available' => true]);
    stockFor($default, 0);

    $other = ProductVariation::factory()->for($product)->create(['is_default' => false, 'is_available' => true]);
    stockFor($other, 0);

    expect(ResolveCardVariation::current($product->fresh(['productVariations.inventory']))->getKey())
        ->toBe($default->getKey());
});
