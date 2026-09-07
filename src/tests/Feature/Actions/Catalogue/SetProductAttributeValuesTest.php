<?php

declare(strict_types=1);

use App\Actions\Catalogue\SetProductAttributeValues;
use App\Exceptions\AttributeNotAllowedForCategoryException;
use App\Exceptions\AttributeValueIsAVariationAxisException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * "What is this made of" — attribute_value_product, the descriptive half of
 * the same vocabulary the variation grid uses. The asymmetry that justifies
 * the second pivot is that multiple values per attribute are legal HERE and
 * illegal on a variation: "50% cotton, 50% polyester" is one fact about one
 * sellable thing, and expressing it as two variations would invent two SKUs
 * that do not exist.
 *
 * explanation/product-variability.md ·
 * reference/write-rules/product-attribute-values.md
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

/**
 * A product plus an unrestricted, filterable attribute with two values.
 *
 * @return array{0: Product, 1: Attribute, 2: AttributeValue, 3: AttributeValue}
 */
function productWithDescriptiveAttribute(): array
{
    $product = Product::factory()->create();
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $cotton = AttributeValue::factory()->for($material)->create();
    $polyester = AttributeValue::factory()->for($material)->create();

    return [$product, $material, $cotton, $polyester];
}

it('sets a product\'s descriptive values', function (): void {
    [$product, , $cotton] = productWithDescriptiveAttribute();

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);

    expect($product->descriptiveAttributeValues()->pluck('attribute_values.id')->all())->toBe([$cotton->id]);
});

it('allows two values of the same attribute, unlike a variation', function (): void {
    // The whole reason this pivot exists. SetVariationAttributeValues
    // refuses exactly this shape with DuplicateVariationAttributeException,
    // because two values for one axis makes "which variation is
    // Material=cotton?" ambiguous. A product-level fact has no such problem.
    [$product, , $cotton, $polyester] = productWithDescriptiveAttribute();

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id, $polyester->id], null);

    expect($product->descriptiveAttributeValues()->count())->toBe(2);
});

it('replaces the whole set rather than merging into it', function (): void {
    [$product, , $cotton, $polyester] = productWithDescriptiveAttribute();

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);
    app(SetProductAttributeValues::class)->handle($product, [$polyester->id], null);

    expect($product->descriptiveAttributeValues()->pluck('attribute_values.id')->all())->toBe([$polyester->id]);
});

it('clears the set when given an empty list', function (): void {
    [$product, , $cotton] = productWithDescriptiveAttribute();

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);
    app(SetProductAttributeValues::class)->handle($product, [], null);

    expect($product->descriptiveAttributeValues()->count())->toBe(0);
});

it('collapses a duplicate id silently', function (): void {
    [$product, , $cotton] = productWithDescriptiveAttribute();

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id, $cotton->id], null);

    expect($product->descriptiveAttributeValues()->count())->toBe(1);
});

it('refuses a value whose attribute is one of the product\'s own variation axes', function (): void {
    // A shirt that varies by Colour cannot also assert one product-wide
    // Colour: a filter reading both would return it for a colour no
    // variation actually has.
    [$product, $material, $cotton] = productWithDescriptiveAttribute();
    $product->attributes()->attach($material->id);

    expect(fn () => app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null))
        ->toThrow(AttributeValueIsAVariationAxisException::class);

    expect($product->descriptiveAttributeValues()->count())->toBe(0);
});

it('refuses a value whose attribute is not allowed for the product\'s category', function (): void {
    // The same attribute_product_category allow-list CreateProduct enforces
    // for axes — a perfume has no business carrying a shoe size
    // descriptively either.
    $footwear = ProductCategory::factory()->create();
    $beauty = ProductCategory::factory()->create();
    $product = Product::factory()->create(['product_category_id' => $beauty->id]);

    $shoeSize = Attribute::factory()->create();
    $shoeSize->productCategories()->attach($footwear->id);
    $size42 = AttributeValue::factory()->for($shoeSize)->create();

    expect(fn () => app(SetProductAttributeValues::class)->handle($product, [$size42->id], null))
        ->toThrow(AttributeNotAllowedForCategoryException::class);

    expect($product->descriptiveAttributeValues()->count())->toBe(0);
});

it('refuses a product soft-deleted since the page loaded', function (): void {
    [$product, , $cotton] = productWithDescriptiveAttribute();
    $product->delete();

    expect(fn () => app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null))
        ->toThrow(RemovedFromCatalogueException::class);
});

it('denies an actor without update_product', function (): void {
    [$product, , $cotton] = productWithDescriptiveAttribute();
    $actor = catalogueActor('view_product');

    expect(fn () => app(SetProductAttributeValues::class)->handle($product, [$cotton->id], $actor))
        ->toThrow(AuthorizationException::class);
});

it('allows an actor holding update_product', function (): void {
    [$product, , $cotton] = productWithDescriptiveAttribute();
    $actor = catalogueActor('update_product');

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], $actor);

    expect($product->descriptiveAttributeValues()->count())->toBe(1);
});

it('refuses a value whose attribute is variation-only, even when the product does not vary by it', function (): void {
    // Live bug: a sneaker varying by `shoe-size` (not `size`) accepted
    // Size S *and* Size L as product-wide values — "this product is both S
    // and L", which no single sellable thing can claim. The per-product
    // axis check had nothing to fire on, because the product had never
    // listed Size as one of its own axes. Whether an attribute is a
    // choosing one is a property of the attribute, not of a product's data.
    $size = Attribute::factory()->create(['is_variation_only' => true]);
    $small = AttributeValue::factory()->for($size)->create();
    $large = AttributeValue::factory()->for($size)->create();

    $product = Product::factory()->create();

    expect($product->attributes()->where('attributes.id', $size->id)->exists())->toBeFalse();

    expect(fn () => app(SetProductAttributeValues::class)->handle($product, [$small->id, $large->id], null))
        ->toThrow(AttributeValueIsAVariationAxisException::class);

    expect($product->descriptiveAttributeValues()->count())->toBe(0);
});

it('still allows a descriptive attribute that is not variation-only', function (): void {
    // The guard must not swallow the ordinary case it sits beside.
    [$product, , $cotton] = productWithDescriptiveAttribute();

    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);

    expect($product->descriptiveAttributeValues()->count())->toBe(1);
});
