<?php

declare(strict_types=1);

use App\Support\ResolveVariationPrice;
use Illuminate\Support\Carbon;

/*
 * §11's price rule has three inputs that can each be null and one window that
 * lives on a different table from the amount it gates: the variation overrides
 * the product's price and its discount, but the schedule is the product's
 * alone. The cases below are the corners of that grid.
 *
 * cartVariation() comes from tests/Pest.php.
 */

it('inherits the product price when the variation overrides nothing', function (): void {
    $variation = cartVariation(product: ['regular_price' => '100.00'], variation: ['price' => null]);

    expect(ResolveVariationPrice::current($variation))->toBe('100.00');
});

it('prefers the variation price over the product one', function (): void {
    $variation = cartVariation(
        product: ['regular_price' => '100.00'],
        variation: ['price' => '129.90'],
    );

    expect(ResolveVariationPrice::current($variation))->toBe('129.90');
});

it('returns the product discount inside an open window', function (): void {
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '79.90',
        'discount_starts_at' => Carbon::now()->subDay(),
        'discount_ends_at' => Carbon::now()->addDay(),
    ]);

    expect(ResolveVariationPrice::current($variation))->toBe('79.90');
});

it('ignores a discount whose window has not opened', function (): void {
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '79.90',
        'discount_starts_at' => Carbon::now()->addDay(),
        'discount_ends_at' => Carbon::now()->addDays(5),
    ]);

    expect(ResolveVariationPrice::current($variation))->toBe('100.00');
});

it('ignores a discount whose window has closed', function (): void {
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '79.90',
        'discount_starts_at' => Carbon::now()->subDays(5),
        'discount_ends_at' => Carbon::now()->subDay(),
    ]);

    expect(ResolveVariationPrice::current($variation))->toBe('100.00');
});

it('treats a discount with no dates at all as always active', function (): void {
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '79.90',
        'discount_starts_at' => null,
        'discount_ends_at' => null,
    ]);

    // An open-ended window. Both bounds are nullable in the schema, so a
    // permanent price cut needs no dates.
    expect(ResolveVariationPrice::current($variation))->toBe('79.90');
});

it('treats a half-open window as active from its start', function (): void {
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '79.90',
        'discount_starts_at' => Carbon::now()->subDay(),
        'discount_ends_at' => null,
    ]);

    expect(ResolveVariationPrice::current($variation))->toBe('79.90');
});

it('gates the variation discount by the product window', function (): void {
    $variation = cartVariation(
        product: [
            'regular_price' => '100.00',
            'discount_price' => null,
            'discount_starts_at' => Carbon::now()->addDay(),
            'discount_ends_at' => Carbon::now()->addDays(5),
        ],
        variation: ['price' => '129.90', 'discount_price' => '99.90'],
    );

    // One schedule per product; variations override the amount, not the dates.
    // A variation discount outside the product's window is not yet on offer.
    expect(ResolveVariationPrice::current($variation))->toBe('129.90');
});

it('prefers the variation discount over the product one inside the window', function (): void {
    $variation = cartVariation(
        product: [
            'regular_price' => '100.00',
            'discount_price' => '79.90',
            'discount_starts_at' => Carbon::now()->subDay(),
            'discount_ends_at' => Carbon::now()->addDay(),
        ],
        variation: ['price' => '129.90', 'discount_price' => '99.90'],
    );

    expect(ResolveVariationPrice::current($variation))->toBe('99.90');
});

it('falls back to the product discount when the variation overrides only the base price', function (): void {
    $variation = cartVariation(
        product: [
            'regular_price' => '100.00',
            'discount_price' => '79.90',
            'discount_starts_at' => Carbon::now()->subDay(),
            'discount_ends_at' => Carbon::now()->addDay(),
        ],
        variation: ['price' => '129.90', 'discount_price' => null],
    );

    // Worth knowing rather than assuming: a variation that costs more than the
    // product still sells at the product's discount, because null means
    // inherit and the two overrides are independent.
    expect(ResolveVariationPrice::current($variation))->toBe('79.90');
});

it('returns a string, not a float', function (): void {
    $variation = cartVariation(product: ['regular_price' => '100.00']);

    // CLAUDE.md forbids float for money, and every caller feeds this straight
    // into bcmath, which takes strings.
    expect(ResolveVariationPrice::current($variation))->toBeString();
});
