<?php

declare(strict_types=1);

use App\Support\Resolvers\ResolveVariationMeasurements;

/*
 * Weight and the three dimension axes are nullable overrides that each
 * inherit the product's value independently. What is ours here is the
 * per-axis fallback — the case the "all three or none" rule this replaced
 * would have forbidden, and the null-means-inherit read that makes reading
 * the column directly wrong.
 *
 * cartVariation() comes from tests/Pest.php.
 */

it('inherits the product weight when the variation overrides nothing', function (): void {
    $variation = cartVariation(
        product: ['weight_g' => 1600],
        variation: ['weight_g' => null],
    );

    expect(ResolveVariationMeasurements::weightGrams($variation))->toBe(1600);
});

it('prefers the variation weight over the product one', function (): void {
    $variation = cartVariation(
        product: ['weight_g' => 1600],
        variation: ['weight_g' => 2400],
    );

    expect(ResolveVariationMeasurements::weightGrams($variation))->toBe(2400);
});

it('resolves each dimension axis independently', function (): void {
    // The hardcover case: same page size, thicker spine. Only height is
    // overridden; the other two inherit rather than being restated.
    $variation = cartVariation(
        product: ['length_mm' => 240, 'width_mm' => 170, 'height_mm' => 20],
        variation: ['length_mm' => null, 'width_mm' => null, 'height_mm' => 35],
    );

    expect(ResolveVariationMeasurements::dimensionsMillimetres($variation))->toBe([
        'length' => 240,
        'width' => 170,
        'height' => 35,
    ]);
});

it('returns null for a figure neither the variation nor the product records', function (): void {
    $variation = cartVariation(
        product: ['weight_g' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null],
        variation: ['weight_g' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null],
    );

    expect(ResolveVariationMeasurements::weightGrams($variation))->toBeNull()
        ->and(ResolveVariationMeasurements::dimensionsMillimetres($variation))->toBe([
            'length' => null,
            'width' => null,
            'height' => null,
        ]);
});

it('reports a variation as shippable only when every courier figure resolves', function (): void {
    $complete = cartVariation(
        product: ['weight_g' => 1600, 'length_mm' => 240, 'width_mm' => 170, 'height_mm' => 20],
        variation: ['weight_g' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null],
    );

    // Inherited figures count: the parcel is measurable even though the
    // variation itself records nothing.
    expect(ResolveVariationMeasurements::isShippable($complete))->toBeTrue();

    $missingOneAxis = cartVariation(
        product: ['weight_g' => 1600, 'length_mm' => 240, 'width_mm' => 170, 'height_mm' => null],
        variation: ['weight_g' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null],
    );

    expect(ResolveVariationMeasurements::isShippable($missingOneAxis))->toBeFalse();
});
