<?php

declare(strict_types=1);

use App\Actions\Catalogue\SetProductAttributeValues;
use App\Livewire\Catalogue\ProductList;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariation;
use Livewire\Livewire;

/*
 * Search matches an attribute value's own text ("Linen", "Red") as well as
 * the product's name and short_description — a shopper typing a material or
 * colour they remember has no reason to know whether that fact lives on the
 * product itself (descriptive) or on one of its variations (an axis).
 */

it('matches a product by its own name', function (): void {
    $match = Product::factory()->create(['name' => 'Linen Shirt', 'is_available' => true]);
    $other = Product::factory()->create(['name' => 'Cotton Shirt', 'is_available' => true]);

    $ids = Livewire::test(ProductList::class)
        ->set('search', 'linen')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($match->id)
        ->and($ids)->not->toContain($other->id);
});

it('matches a product by a descriptive attribute value', function (): void {
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $linen = AttributeValue::factory()->for($material)->create(['value' => 'Linen']);
    $cotton = AttributeValue::factory()->for($material)->create(['value' => 'Cotton']);

    $match = Product::factory()->create(['name' => 'Summer Shirt', 'is_available' => true]);
    app(SetProductAttributeValues::class)->handle($match, [$linen->id], null);

    $other = Product::factory()->create(['name' => 'Winter Shirt', 'is_available' => true]);
    app(SetProductAttributeValues::class)->handle($other, [$cotton->id], null);

    $ids = Livewire::test(ProductList::class)
        ->set('search', 'linen')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($match->id)
        ->and($ids)->not->toContain($other->id);
});

it('matches a product by a variation-only axis value', function (): void {
    // Colour/Size are is_variation_only, so this fact can only ever reach a
    // product through a variation's own axis — never through the
    // descriptive pivot search also checks.
    $colour = Attribute::factory()->create(['is_filterable' => true, 'is_variation_only' => true]);
    $red = AttributeValue::factory()->for($colour)->create(['value' => 'Red']);
    $blue = AttributeValue::factory()->for($colour)->create(['value' => 'Blue']);

    $match = Product::factory()->create(['name' => 'Everyday Jacket', 'is_available' => true]);
    $match->attributes()->attach($colour->id);
    $matchVariation = ProductVariation::factory()->for($match)->create(['is_available' => true]);
    $matchVariation->attributeValues()->attach($red->id);

    $other = Product::factory()->create(['name' => 'Weekend Jacket', 'is_available' => true]);
    $other->attributes()->attach($colour->id);
    $otherVariation = ProductVariation::factory()->for($other)->create(['is_available' => true]);
    $otherVariation->attributeValues()->attach($blue->id);

    $ids = Livewire::test(ProductList::class)
        ->set('search', 'red')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($match->id)
        ->and($ids)->not->toContain($other->id);
});

it('does not match a product carrying an unrelated attribute value', function (): void {
    $material = Attribute::factory()->create(['is_filterable' => true]);
    AttributeValue::factory()->for($material)->create(['value' => 'Cotton']);

    $product = Product::factory()->create(['name' => 'Plain Shirt', 'is_available' => true]);

    $ids = Livewire::test(ProductList::class)
        ->set('search', 'linen')
        ->viewData('products')
        ->pluck('id')
        ->all();

    expect($ids)->not->toContain($product->id);
});
