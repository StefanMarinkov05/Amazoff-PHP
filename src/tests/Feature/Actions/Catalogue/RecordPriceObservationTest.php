<?php

declare(strict_types=1);

use App\Actions\Catalogue\RecordPriceObservation;
use App\Models\Product;
use App\Models\ProductPriceHistory;

/*
 * RecordPriceObservation — the write side of the Omnibus prior-price display
 * (ADR-0021). Records a product's *effective* selling price (discounted while
 * the window is open, regular otherwise).
 */

function pricedProduct(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'regular_price' => '100.00',
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
    ], $overrides));
}

it('records the regular price when the product is not on sale', function (): void {
    $product = pricedProduct();

    $row = app(RecordPriceObservation::class)->handle($product);

    expect($row)->toBeInstanceOf(ProductPriceHistory::class)
        ->and((string) $row->price)->toBe('100.00')
        ->and($row->recorded_at)->not->toBeNull();
});

it('records the discounted price when the discount window is open', function (): void {
    $product = pricedProduct([
        'discount_price' => '70.00',
        'discount_starts_at' => now()->subDay(),
        'discount_ends_at' => now()->addWeek(),
    ]);

    $row = app(RecordPriceObservation::class)->handle($product);

    expect((string) $row->price)->toBe('70.00');
});

it('records the regular price when a discount is set but its window has not opened', function (): void {
    $product = pricedProduct([
        'discount_price' => '70.00',
        'discount_starts_at' => now()->addWeek(),
        'discount_ends_at' => now()->addWeeks(2),
    ]);

    $row = app(RecordPriceObservation::class)->handle($product);

    expect((string) $row->price)->toBe('100.00');
});

it('does not write a second row when the effective price has not changed', function (): void {
    $product = pricedProduct();

    app(RecordPriceObservation::class)->handle($product);
    $second = app(RecordPriceObservation::class)->handle($product);

    expect($second)->toBeNull()
        ->and(ProductPriceHistory::where('product_id', $product->getKey())->count())->toBe(1);
});

it('writes again once the effective price does change', function (): void {
    $product = pricedProduct();
    app(RecordPriceObservation::class)->handle($product);

    $product->update(['regular_price' => '90.00']);
    $row = app(RecordPriceObservation::class)->handle($product);

    expect((string) $row->price)->toBe('90.00')
        ->and(ProductPriceHistory::where('product_id', $product->getKey())->count())->toBe(2);
});

it('force mode writes even when the price is unchanged', function (): void {
    $product = pricedProduct();
    app(RecordPriceObservation::class)->handle($product);

    app(RecordPriceObservation::class)->handle($product, force: true);

    expect(ProductPriceHistory::where('product_id', $product->getKey())->count())->toBe(2);
});
