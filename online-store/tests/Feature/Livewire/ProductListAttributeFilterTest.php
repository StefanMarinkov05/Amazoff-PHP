<?php

declare(strict_types=1);

use App\Actions\Catalogue\SetProductAttributeValues;
use App\Livewire\Catalogue\ProductList;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use Livewire\Livewire;

/*
 * Filtering the catalogue by descriptive attribute values — the reason
 * attribute_value_product is a controlled vocabulary rather than free-text
 * product_specifications, which cannot back a filter because nothing
 * constrains "Cotton"/"cotton"/"100% Cotton" to one value.
 *
 * ?attributeValueIds[]= is #[Url]-bound and reaches a query, so it is
 * attacker-controlled input under ADR-0014's allow-list rule — the hostile
 * cases below are not decoration.
 */

/**
 * Two products with a shared Material attribute: one cotton, one polyester,
 * and one carrying both.
 *
 * @return array{0: AttributeValue, 1: AttributeValue, 2: Product, 3: Product, 4: Product}
 */
function catalogueWithMaterials(): array
{
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $cotton = AttributeValue::factory()->for($material)->create();
    $polyester = AttributeValue::factory()->for($material)->create();

    $cottonOnly = Product::factory()->create(['is_available' => true]);
    $polyesterOnly = Product::factory()->create(['is_available' => true]);
    $blend = Product::factory()->create(['is_available' => true]);

    app(SetProductAttributeValues::class)->handle($cottonOnly, [$cotton->id], null);
    app(SetProductAttributeValues::class)->handle($polyesterOnly, [$polyester->id], null);
    app(SetProductAttributeValues::class)->handle($blend, [$cotton->id, $polyester->id], null);

    return [$cotton, $polyester, $cottonOnly, $polyesterOnly, $blend];
}

it('narrows the catalogue to products carrying the selected value', function (): void {
    [$cotton, , $cottonOnly, $polyesterOnly, $blend] = catalogueWithMaterials();

    $results = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id]])
        ->test(ProductList::class)
        ->viewData('products');

    $ids = collect($results->items())->pluck('id')->all();

    expect($ids)->toContain($cottonOnly->id)
        ->and($ids)->toContain($blend->id)
        ->and($ids)->not->toContain($polyesterOnly->id);
});

it('ORs two values of the same attribute rather than requiring both at once', function (): void {
    // Corrected live: checking Colour=Black and Colour=White both returned
    // zero results, because every value was AND-ed regardless of attribute
    // — "Black AND White" is not a real combination, no variation can ever
    // be both. Checking two boxes in one facet group means "either", the
    // ordinary meaning a shopper expects; here that is Cotton or Polyester,
    // which is every product in this fixture.
    [$cotton, $polyester, $cottonOnly, $polyesterOnly, $blend] = catalogueWithMaterials();

    $results = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id, $polyester->id]])
        ->test(ProductList::class)
        ->viewData('products');

    $ids = collect($results->items())->pluck('id')->all();

    expect($ids)->toContain($cottonOnly->id)
        ->and($ids)->toContain($polyesterOnly->id)
        ->and($ids)->toContain($blend->id);
});

it('ANDs across two different attributes while ORing within each', function (): void {
    // The other half of the rule: Material values OR together, but a
    // Material selection still narrows against a Colour selection — the two
    // facts can coexist on one product, unlike two values of one attribute.
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $cotton = AttributeValue::factory()->for($material)->create();
    $colour = Attribute::factory()->create(['is_filterable' => true, 'is_variation_only' => true]);
    $black = AttributeValue::factory()->for($colour)->create();
    $white = AttributeValue::factory()->for($colour)->create();

    $cottonBlack = Product::factory()->create(['is_available' => true]);
    app(SetProductAttributeValues::class)->handle($cottonBlack, [$cotton->id], null);
    $cottonBlack->attributes()->attach($colour->id);
    ProductVariation::factory()->for($cottonBlack)->create(['is_available' => true])
        ->attributeValues()->attach($black->id);

    $cottonWhite = Product::factory()->create(['is_available' => true]);
    app(SetProductAttributeValues::class)->handle($cottonWhite, [$cotton->id], null);
    $cottonWhite->attributes()->attach($colour->id);
    ProductVariation::factory()->for($cottonWhite)->create(['is_available' => true])
        ->attributeValues()->attach($white->id);

    $noColourMatch = Product::factory()->create(['is_available' => true]);
    app(SetProductAttributeValues::class)->handle($noColourMatch, [$cotton->id], null);

    $results = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id, $black->id, $white->id]])
        ->test(ProductList::class)
        ->viewData('products');
    $ids = collect($results->items())->pluck('id')->all();

    expect($ids)->toContain($cottonBlack->id)
        ->and($ids)->toContain($cottonWhite->id)
        ->and($ids)->not->toContain($noColourMatch->id);
});

it('ignores a value belonging to an attribute that is not filterable', function (): void {
    // is_filterable finally has a consumer. A value on a non-filterable
    // attribute narrows nothing rather than quietly filtering by something
    // the sidebar never offered.
    $internal = Attribute::factory()->create(['is_filterable' => false]);
    $value = AttributeValue::factory()->for($internal)->create();

    $product = Product::factory()->create(['is_available' => true]);
    $other = Product::factory()->create(['is_available' => true]);
    app(SetProductAttributeValues::class)->handle($product, [$value->id], null);

    $results = Livewire::withQueryParams(['attributeValueIds' => [$value->id]])
        ->test(ProductList::class)
        ->viewData('products');

    expect(collect($results->items())->pluck('id')->all())->toContain($other->id);
});

it('does not crash on an attribute value id too large for PHP to represent as an int', function (): void {
    // The same incident class as ProductDetails::$variationId and
    // ProductList::$minRating — #[Url] hydration assigns the raw value
    // before any of this component's code runs, so the property is `mixed`
    // and sanitised in updatedAttributeValueIds().
    Product::factory()->create(['is_available' => true]);

    Livewire::withQueryParams(['attributeValueIds' => ['99999999999999999999999999999999']])
        ->test(ProductList::class)
        ->assertOk();
});

it('does not crash on a non-numeric or nested attribute value id', function (): void {
    Product::factory()->create(['is_available' => true]);

    Livewire::withQueryParams(['attributeValueIds' => ['not-a-number', ['nested']]])
        ->test(ProductList::class)
        ->assertOk();
});

it('does not crash when the whole parameter is a scalar rather than an array', function (): void {
    // ?attributeValueIds=7 rather than ?attributeValueIds[]=7 — a
    // hand-edited URL, and a shape (array) cast has to survive.
    Product::factory()->create(['is_available' => true]);

    Livewire::withQueryParams(['attributeValueIds' => '7'])
        ->test(ProductList::class)
        ->assertOk();
});

it('offers a facet only for values a visible product actually carries', function (): void {
    // A facet with nothing behind it promises results that are not there —
    // the same rule categories() follows. Needs a category picked, since
    // facets are category-scoped; the unused value is on an attribute
    // allowed for the same category, so only "no product carries it"
    // separates the two.
    [$cotton] = catalogueWithMaterials();
    $category = ProductCategory::factory()->create();

    /** @var Attribute $material */
    $material = $cotton->attribute;
    $material->productCategories()->attach($category->id);

    $unusedAttribute = Attribute::factory()->create(['is_filterable' => true]);
    $unusedAttribute->productCategories()->attach($category->id);
    $unused = AttributeValue::factory()->for($unusedAttribute)->create();

    $product = Product::factory()->create([
        'is_available' => true,
        'product_category_id' => $category->id,
    ]);
    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);

    $facets = Livewire::withQueryParams(['category' => $category->slug])
        ->test(ProductList::class)
        ->instance()
        ->attributeFacets();
    $offeredIds = $facets->flatten()->pluck('id')->all();

    expect($offeredIds)->toContain($cotton->id)
        ->and($offeredIds)->not->toContain($unused->id);
});

/*
 * Facets are category-scoped. Before a category is picked only the generic
 * filters (brand, price, rating, stock, sale) apply — Colour and Size mean
 * nothing across a catalogue that also holds power tools and moisturiser.
 * Once one is picked, its allowed attributes appear, inherited from every
 * ancestor so scoping to a master category reaches every leaf under it.
 */

it('offers no attribute facets until a category is picked', function (): void {
    catalogueWithMaterials();

    expect(Livewire::test(ProductList::class)->instance()->attributeFacets())->toBeEmpty();
});

it('offers a category\'s own allowed attributes once it is picked', function (): void {
    [$cotton] = catalogueWithMaterials();
    $category = ProductCategory::factory()->create();

    /** @var Attribute $material */
    $material = $cotton->attribute;
    $material->productCategories()->attach($category->id);

    $product = Product::factory()->create([
        'is_available' => true,
        'product_category_id' => $category->id,
    ]);
    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);

    $facets = Livewire::withQueryParams(['category' => $category->slug])
        ->test(ProductList::class)
        ->instance()
        ->attributeFacets();

    expect($facets->flatten()->pluck('id')->all())->toContain($cotton->id);
});

it('inherits an ancestor\'s allowed attributes down to a deep subcategory', function (): void {
    // "Colour scoped to Clothing" must reach "Clothing > Men > Tops"
    // without being re-scoped at every depth — the inheritance rule.
    [$cotton] = catalogueWithMaterials();
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    /** @var Attribute $material */
    $material = $cotton->attribute;
    $material->productCategories()->attach($top->id);

    $product = Product::factory()->create([
        'is_available' => true,
        'product_category_id' => $leaf->id,
    ]);
    app(SetProductAttributeValues::class)->handle($product, [$cotton->id], null);

    $facets = Livewire::withQueryParams(['category' => $leaf->slug])
        ->test(ProductList::class)
        ->instance()
        ->attributeFacets();

    expect($facets->flatten()->pluck('id')->all())->toContain($cotton->id);
});

it('orders a facet\'s values by their own sort_order, not alphabetically', function (): void {
    // Reported live: Size read L, M, S, XL, XS — alphabetical order, not the
    // XS/S/M/L/XL/XXL a shopper expects. Collection::sortBy() only accepts
    // one criterion per call; a bare array of closures silently sorted by
    // neither, so grouping-then-sorting-within-each-group is required.
    $category = ProductCategory::factory()->create();
    $size = Attribute::factory()->create(['is_filterable' => true]);
    $size->productCategories()->attach($category->id);

    $large = AttributeValue::factory()->for($size)->create(['value' => 'L', 'sort_order' => 3]);
    $small = AttributeValue::factory()->for($size)->create(['value' => 'S', 'sort_order' => 1]);
    $medium = AttributeValue::factory()->for($size)->create(['value' => 'M', 'sort_order' => 2]);

    foreach ([$large, $small, $medium] as $value) {
        $product = Product::factory()->create(['is_available' => true, 'product_category_id' => $category->id]);
        app(SetProductAttributeValues::class)->handle($product, [$value->id], null);
    }

    $facets = Livewire::withQueryParams(['category' => $category->slug])
        ->test(ProductList::class)
        ->instance()
        ->attributeFacets();

    expect($facets->get($size->name)->pluck('value')->all())->toBe(['S', 'M', 'L']);
});

it('does not offer an attribute scoped to an unrelated category branch', function (): void {
    [$cotton] = catalogueWithMaterials();
    $clothing = ProductCategory::factory()->create();
    $beauty = ProductCategory::factory()->create();

    /** @var Attribute $material */
    $material = $cotton->attribute;
    $material->productCategories()->attach($clothing->id);

    $product = Product::factory()->create([
        'is_available' => true,
        'product_category_id' => $beauty->id,
    ]);

    $facets = Livewire::withQueryParams(['category' => $beauty->slug])
        ->test(ProductList::class)
        ->instance()
        ->attributeFacets();

    expect($facets->flatten()->pluck('id')->all())->not->toContain($cotton->id);
});

it('drops one value at a time when its chip is dismissed', function (): void {
    // The values are AND-ed, so dismissing one chip widens the search by a
    // single step rather than clearing the whole set.
    [$cotton, $polyester] = catalogueWithMaterials();

    $component = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id, $polyester->id]])
        ->test(ProductList::class)
        ->call('clearFilter', 'attributeValue:'.$cotton->id);

    expect($component->get('attributeValueIds'))->toBe([$polyester->id]);
});

/*
 * Facet counts must narrow across attributes but never against themselves.
 * Reported live: selecting Material=Denim (3 products) still showed Colour
 * and Size at their whole-catalogue counts instead of narrowing to what
 * those 3 Denim products actually have — skip: 'attributeValueIds' dropped
 * every attribute filter at once when computing a facet's count, rather
 * than just the attribute being evaluated.
 */

it('narrows a facet\'s counts to match another selected attribute', function (): void {
    $category = ProductCategory::factory()->create();

    $material = Attribute::factory()->create(['is_filterable' => true]);
    $material->productCategories()->attach($category->id);
    $denim = AttributeValue::factory()->for($material)->create();
    $cotton = AttributeValue::factory()->for($material)->create();

    $colour = Attribute::factory()->create(['is_filterable' => true, 'is_variation_only' => true]);
    $colour->productCategories()->attach($category->id);
    $blue = AttributeValue::factory()->for($colour)->create();
    $red = AttributeValue::factory()->for($colour)->create();

    // A blue, denim product.
    $blueDenim = Product::factory()->create(['is_available' => true, 'product_category_id' => $category->id]);
    app(SetProductAttributeValues::class)->handle($blueDenim, [$denim->id], null);
    $blueDenim->attributes()->attach($colour->id);
    $blueDenimVariation = ProductVariation::factory()->for($blueDenim)->create(['is_available' => true]);
    $blueDenimVariation->attributeValues()->attach($blue->id);

    // A red, cotton product — same colour attribute, different material.
    $redCotton = Product::factory()->create(['is_available' => true, 'product_category_id' => $category->id]);
    app(SetProductAttributeValues::class)->handle($redCotton, [$cotton->id], null);
    $redCotton->attributes()->attach($colour->id);
    $redCottonVariation = ProductVariation::factory()->for($redCotton)->create(['is_available' => true]);
    $redCottonVariation->attributeValues()->attach($red->id);

    $facets = Livewire::withQueryParams([
        'category' => $category->slug,
        'attributeValueIds' => [$denim->id],
    ])->test(ProductList::class)->instance()->attributeFacets();

    $colourFacet = $facets->get($colour->name);

    // Selecting Denim must narrow Colour to Blue only — Blue is the only
    // colour a Denim product actually has.
    expect($colourFacet->pluck('id')->all())->toBe([$blue->id])
        ->and($colourFacet->firstWhere('id', $blue->id)->products_count)->toBe(1);
});

it('does not let a value\'s own selection shrink its own facet count to zero', function (): void {
    $category = ProductCategory::factory()->create();
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $material->productCategories()->attach($category->id);
    $denim = AttributeValue::factory()->for($material)->create();

    $product = Product::factory()->create(['is_available' => true, 'product_category_id' => $category->id]);
    app(SetProductAttributeValues::class)->handle($product, [$denim->id], null);

    $facets = Livewire::withQueryParams([
        'category' => $category->slug,
        'attributeValueIds' => [$denim->id],
    ])->test(ProductList::class)->instance()->attributeFacets();

    $materialFacet = $facets->get($material->name);

    expect($materialFacet->firstWhere('id', $denim->id)->products_count)->toBe(1);
});

/*
 * Variation-sourced attribute values. Colour and Size are is_variation_only
 * — they can never be a product's own descriptive value — so before this
 * they had a real allow-list entry, appeared nowhere in the sidebar, and
 * could not be filtered by at all. Reported live: picking "Men" showed
 * Material only, never Colour or Size, on a category that plainly has both.
 *
 * The fix reads either pivot: a value counts if the product carries it
 * descriptively, or if any of the product's own variations carries it as an
 * axis. A customer filtering "Blue" does not care which table answers it.
 */

it('offers a variation-only attribute as a facet when a variation carries it', function (): void {
    $category = ProductCategory::factory()->create();
    $colour = Attribute::factory()->create(['is_filterable' => true, 'is_variation_only' => true]);
    $colour->productCategories()->attach($category->id);
    $black = AttributeValue::factory()->for($colour)->create();

    $product = Product::factory()->create([
        'is_available' => true,
        'product_category_id' => $category->id,
    ]);
    $product->attributes()->attach($colour->id);
    $variation = ProductVariation::factory()->for($product)->create(['is_available' => true]);
    $variation->attributeValues()->attach($black->id);

    $facets = Livewire::withQueryParams(['category' => $category->slug])
        ->test(ProductList::class)
        ->instance()
        ->attributeFacets();

    expect($facets->flatten()->pluck('id')->all())->toContain($black->id);
});

it('narrows the catalogue by a variation-only value', function (): void {
    $category = ProductCategory::factory()->create();
    // is_filterable is randomised by the factory's own default — pinned
    // explicitly here, since an unfiltered attribute makes
    // filterableAttributeValueIds() return empty and this test's own filter
    // silently apply to nothing, passing for the wrong reason on the runs
    // where the coin flip landed the other way. Caught as a real,
    // intermittent flake, not assumed from reading the factory.
    $colour = Attribute::factory()->create(['is_filterable' => true, 'is_variation_only' => true]);
    $colour->productCategories()->attach($category->id);
    $black = AttributeValue::factory()->for($colour)->create();
    $white = AttributeValue::factory()->for($colour)->create();

    $blackProduct = Product::factory()->create(['is_available' => true, 'product_category_id' => $category->id]);
    $blackProduct->attributes()->attach($colour->id);
    $blackVariation = ProductVariation::factory()->for($blackProduct)->create(['is_available' => true]);
    $blackVariation->attributeValues()->attach($black->id);

    $whiteProduct = Product::factory()->create(['is_available' => true, 'product_category_id' => $category->id]);
    $whiteProduct->attributes()->attach($colour->id);
    $whiteVariation = ProductVariation::factory()->for($whiteProduct)->create(['is_available' => true]);
    $whiteVariation->attributeValues()->attach($white->id);

    $results = Livewire::withQueryParams(['attributeValueIds' => [$black->id]])
        ->test(ProductList::class)
        ->viewData('products');
    $ids = collect($results->items())->pluck('id')->all();

    expect($ids)->toContain($blackProduct->id)
        ->and($ids)->not->toContain($whiteProduct->id);
});

it('matches a product through either pivot for the same filter', function (): void {
    // One product answers "Cotton" descriptively, another through a
    // variation's own axis value — both are legitimate ways to carry the
    // same fact, and a customer filtering by it should see both.
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $cotton = AttributeValue::factory()->for($material)->create();

    $descriptive = Product::factory()->create(['is_available' => true]);
    app(SetProductAttributeValues::class)->handle($descriptive, [$cotton->id], null);

    $viaVariation = Product::factory()->create(['is_available' => true]);
    $viaVariation->attributes()->attach($material->id);
    $variation = ProductVariation::factory()->for($viaVariation)->create(['is_available' => true]);
    $variation->attributeValues()->attach($cotton->id);

    $results = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id]])
        ->test(ProductList::class)
        ->viewData('products');
    $ids = collect($results->items())->pluck('id')->all();

    expect($ids)->toContain($descriptive->id)
        ->and($ids)->toContain($viaVariation->id);
});

/*
 * The facet UI: clickable toggle buttons rather than a <select multiple> —
 * toggleAttributeValue() adds or removes one value directly from the flat
 * attributeValueIds list, so no per-attribute staging property is needed.
 */

it('adds a value to attributeValueIds when its facet button is toggled on', function (): void {
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $cotton = AttributeValue::factory()->for($material)->create();

    $component = Livewire::test(ProductList::class)
        ->call('toggleAttributeValue', $cotton->id);

    expect($component->get('attributeValueIds'))->toBe([$cotton->id]);
});

it('removes a value from attributeValueIds when its facet button is toggled off', function (): void {
    $material = Attribute::factory()->create(['is_filterable' => true]);
    $cotton = AttributeValue::factory()->for($material)->create();

    $component = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id]])
        ->test(ProductList::class)
        ->call('toggleAttributeValue', $cotton->id);

    expect($component->get('attributeValueIds'))->toBe([]);
});

it('drops just the dismissed value when its chip is individually removed, keeping the rest', function (): void {
    [$cotton, $polyester] = catalogueWithMaterials();

    $component = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id, $polyester->id]])
        ->test(ProductList::class)
        ->call('clearFilter', 'attributeValue:'.$cotton->id);

    expect($component->get('attributeValueIds'))->toBe([$polyester->id]);
});

it('clears every selected value when all filters are cleared', function (): void {
    [$cotton] = catalogueWithMaterials();

    $component = Livewire::withQueryParams(['attributeValueIds' => [$cotton->id]])
        ->test(ProductList::class)
        ->call('clearFilters');

    expect($component->get('attributeValueIds'))->toBe([]);
});
