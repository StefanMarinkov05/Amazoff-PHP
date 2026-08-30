<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\ProductCategory;
use App\Support\ResolveAllowedAttributes;

/*
 * attribute_product_category is an allow-list an admin opts an attribute
 * into. An attribute with no rows there is unrestricted — allowed for every
 * category — not "allowed nowhere", which is the one rule every test here
 * either confirms or narrows.
 */

it('allows an attribute with no category rows for any category', function (): void {
    $unrestricted = Attribute::factory()->create();
    $category = ProductCategory::factory()->create();

    expect(ResolveAllowedAttributes::forCategory($category))->toContain($unrestricted->id);
});

it('allows a scoped attribute for the exact category it is scoped to', function (): void {
    $category = ProductCategory::factory()->create();
    $attribute = Attribute::factory()->create();
    $attribute->productCategories()->attach($category->id);

    expect(ResolveAllowedAttributes::forCategory($category))->toContain($attribute->id);
});

it('allows a scoped attribute for a descendant, at any depth', function (): void {
    $top = ProductCategory::factory()->create();               // Clothing
    $mid = ProductCategory::factory()->childOf($top)->create(); // Men
    $leaf = ProductCategory::factory()->childOf($mid)->create(); // Tops
    $colour = Attribute::factory()->create();
    $colour->productCategories()->attach($top->id);

    expect(ResolveAllowedAttributes::forCategory($leaf))->toContain($colour->id);
});

it('refuses a scoped attribute for an unrelated category', function (): void {
    $footwear = ProductCategory::factory()->create();
    $beauty = ProductCategory::factory()->create();
    $shoeSize = Attribute::factory()->create();
    $shoeSize->productCategories()->attach($footwear->id);

    expect(ResolveAllowedAttributes::forCategory($beauty))->not->toContain($shoeSize->id);
});

it('does not extend a scoped attribute to a sibling category', function (): void {
    // A parent's own scoping reaches down to its children; a child's
    // scoping must not reach sideways to its siblings.
    $parent = ProductCategory::factory()->create();
    $childA = ProductCategory::factory()->childOf($parent)->create();
    $childB = ProductCategory::factory()->childOf($parent)->create();
    $sizeForA = Attribute::factory()->create();
    $sizeForA->productCategories()->attach($childA->id);

    expect(ResolveAllowedAttributes::forCategory($childB))->not->toContain($sizeForA->id);
});

it('does not extend a scoped attribute to its own ancestor', function (): void {
    // Scoping only ever flows down the tree, never up: a value scoped to a
    // leaf is not implicitly available at the parent that holds it.
    $parent = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->childOf($parent)->create();
    $sizeForChild = Attribute::factory()->create();
    $sizeForChild->productCategories()->attach($child->id);

    expect(ResolveAllowedAttributes::forCategory($parent))->not->toContain($sizeForChild->id);
});
