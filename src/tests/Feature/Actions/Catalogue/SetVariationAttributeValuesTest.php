<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\RemoveProductVariation;
use App\Actions\Catalogue\SetVariationAttributeValues;
use App\Exceptions\AttributeValueNotOnProductException;
use App\Exceptions\DuplicateVariationAttributeException;
use App\Exceptions\DuplicateVariationCombinationException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariation;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * "What makes this one different?" — product-variability.md's first
 * question, answered by attribute_value_product_variation. SetVariationAttributeValues
 * owns the whole combination as a set, the same shape SetVariationImages
 * owns the gallery, and enforces the two rules ADR-0005 names as outside the
 * database's reach: a value must belong to an attribute the product actually
 * uses, and no two variations of one product may carry the identical set.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

/**
 * A product with 2 variation axes (Scent, Volume), one variation, and the
 * axes' own values to draw from.
 *
 * @return array{0: Product, 1: ProductVariation, 2: Attribute, 3: Attribute, 4: list<AttributeValue>, 5: list<AttributeValue>}
 */
function productWithAxes(): array
{
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    $scent = Attribute::factory()->create();
    $volume = Attribute::factory()->create();
    $product->attributes()->attach([$scent->id, $volume->id]);

    $scentValues = AttributeValue::factory()->for($scent)->count(2)->create()->all();
    $volumeValues = AttributeValue::factory()->for($volume)->count(2)->create()->all();

    return [$product, $variation, $scent, $volume, $scentValues, $volumeValues];
}

it('sets the combination', function (): void {
    [, $variation, , , $scentValues, $volumeValues] = productWithAxes();

    app(SetVariationAttributeValues::class)->handle(
        $variation,
        [$scentValues[0]->id, $volumeValues[0]->id],
        null,
    );

    expect($variation->attributeValues()->pluck('attribute_values.id')->sort()->values()->all())
        ->toBe(collect([$scentValues[0]->id, $volumeValues[0]->id])->sort()->values()->all());
});

it('replaces the whole combination rather than merging into it', function (): void {
    [, $variation, , , $scentValues, $volumeValues] = productWithAxes();

    app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id], null);
    app(SetVariationAttributeValues::class)->handle($variation, [$volumeValues[0]->id], null);

    expect($variation->attributeValues()->pluck('attribute_values.id')->all())->toBe([$volumeValues[0]->id]);
});

it('clears the combination when given an empty list', function (): void {
    [, $variation, , , $scentValues] = productWithAxes();

    app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id], null);
    app(SetVariationAttributeValues::class)->handle($variation, [], null);

    expect($variation->attributeValues()->count())->toBe(0);
});

it('collapses a duplicate id silently', function (): void {
    [, $variation, , , $scentValues] = productWithAxes();

    app(SetVariationAttributeValues::class)->handle(
        $variation,
        [$scentValues[0]->id, $scentValues[0]->id],
        null,
    );

    expect($variation->attributeValues()->count())->toBe(1);
});

it('refuses a value belonging to an attribute the product does not use', function (): void {
    [, $variation] = productWithAxes();
    $foreign = AttributeValue::factory()->create();

    expect(fn () => app(SetVariationAttributeValues::class)->handle($variation, [$foreign->id], null))
        ->toThrow(AttributeValueNotOnProductException::class);

    expect($variation->attributeValues()->count())->toBe(0);
});

it('refuses two values for the same attribute', function (): void {
    [, $variation, , , $scentValues] = productWithAxes();

    expect(fn () => app(SetVariationAttributeValues::class)->handle(
        $variation,
        [$scentValues[0]->id, $scentValues[1]->id],
        null,
    ))->toThrow(DuplicateVariationAttributeException::class);

    expect($variation->attributeValues()->count())->toBe(0);
});

it('refuses a combination another live variation of the same product already carries', function (): void {
    [$product, $first, , , $scentValues, $volumeValues] = productWithAxes();
    $second = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(SetVariationAttributeValues::class)->handle($first, [$scentValues[0]->id, $volumeValues[0]->id], null);

    expect(fn () => app(SetVariationAttributeValues::class)->handle(
        $second,
        [$scentValues[0]->id, $volumeValues[0]->id],
        null,
    ))->toThrow(DuplicateVariationCombinationException::class);

    expect($second->attributeValues()->count())->toBe(0);
});

it('does not refuse the same combination the variation already carries', function (): void {
    // Re-submitting a form with nothing changed must not trip the
    // uniqueness check against itself.
    [, $variation, , , $scentValues, $volumeValues] = productWithAxes();

    app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id, $volumeValues[0]->id], null);
    app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id, $volumeValues[0]->id], null);

    expect($variation->attributeValues()->pluck('attribute_values.id')->sort()->values()->all())
        ->toBe(collect([$scentValues[0]->id, $volumeValues[0]->id])->sort()->values()->all());
});

it('lets a combination be reused once the variation that held it is removed', function (): void {
    // A soft-deleted variation has already left the catalogue
    // (RemovedFromCatalogueException's own vocabulary) — its old combination
    // is not a permanent reservation.
    [$product, $first, , , $scentValues, $volumeValues] = productWithAxes();
    $second = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(SetVariationAttributeValues::class)->handle($first, [$scentValues[0]->id, $volumeValues[0]->id], null);
    app(RemoveProductVariation::class)->handle($first, null);

    app(SetVariationAttributeValues::class)->handle($second, [$scentValues[0]->id, $volumeValues[0]->id], null);

    expect($second->attributeValues()->count())->toBe(2);
});

it('lets two variations share one value as long as the whole combination differs', function (): void {
    // Two scents at the same volume: only the exact set collides, not any
    // one shared value.
    [$product, $first, , , $scentValues, $volumeValues] = productWithAxes();
    $second = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(SetVariationAttributeValues::class)->handle($first, [$scentValues[0]->id, $volumeValues[0]->id], null);
    app(SetVariationAttributeValues::class)->handle($second, [$scentValues[1]->id, $volumeValues[0]->id], null);

    expect($first->attributeValues()->count())->toBe(2)
        ->and($second->attributeValues()->count())->toBe(2);
});

it('refuses a variation that has been soft-deleted since the page loaded', function (): void {
    [$product, $variation, , , $scentValues] = productWithAxes();

    // A second variation, so the last-variation refusal does not fire first.
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(RemoveProductVariation::class)->handle($variation, null);

    expect(fn () => app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id], null))
        ->toThrow(RemovedFromCatalogueException::class);
});

it('denies an actor without update_product_variation', function (): void {
    [, $variation, , , $scentValues] = productWithAxes();
    $actor = catalogueActor('view_product_variation');

    expect(fn () => app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id], $actor))
        ->toThrow(AuthorizationException::class);
});

it('allows an actor holding update_product_variation', function (): void {
    [, $variation, , , $scentValues] = productWithAxes();
    $actor = catalogueActor('update_product_variation');

    app(SetVariationAttributeValues::class)->handle($variation, [$scentValues[0]->id], $actor);

    expect($variation->attributeValues()->count())->toBe(1);
});
