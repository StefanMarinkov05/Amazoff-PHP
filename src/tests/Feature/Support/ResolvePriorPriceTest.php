<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Support\Resolvers\ResolvePriorPrice;

/*
 * ResolvePriorPrice — the Omnibus "lowest price in the 30 days before the
 * reduction" (ADR-0021). Read-only.
 */

function onSaleProduct(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'regular_price' => '100.00',
        'discount_price' => '70.00',
        'discount_starts_at' => now()->subDays(2),
        'discount_ends_at' => now()->addWeek(),
    ], $overrides));
}

function historyRow(Product $product, string $price, int $daysAgo): void
{
    ProductPriceHistory::factory()->for($product)->daysAgo($daysAgo, $price)->create();
}

it('returns null for a product that is not on sale', function (): void {
    $product = Product::factory()->create([
        'regular_price' => '100.00',
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
    ]);
    historyRow($product, '100.00', 10);

    expect(ResolvePriorPrice::forProduct($product))->toBeNull();
});

it('returns the lowest observation in the 30 days before the discount started', function (): void {
    $product = onSaleProduct(['discount_starts_at' => now()->subDays(2)]);

    // Before the discount: three regular-price observations, one a temporary dip.
    historyRow($product, '100.00', 25);
    historyRow($product, '88.00', 12);   // the lowest in the window
    historyRow($product, '100.00', 5);
    // Inside the discount period — must be ignored (recorded_at >= reference).
    historyRow($product, '70.00', 1);

    expect(ResolvePriorPrice::forProduct($product))->toBe('88.00');
});

it('ignores observations older than 30 days before the reduction', function (): void {
    $product = onSaleProduct(['discount_starts_at' => now()->subDays(1)]);

    historyRow($product, '40.00', 45);   // older than 30 days — ignored
    historyRow($product, '95.00', 10);

    expect(ResolvePriorPrice::forProduct($product))->toBe('95.00');
});

it('falls back to the price in effect entering the window when the window itself has no observation', function (): void {
    $product = onSaleProduct(['discount_starts_at' => now()->subDays(1)]);

    // Only an old observation exists; nothing in the last 30 days.
    historyRow($product, '110.00', 60);

    expect(ResolvePriorPrice::forProduct($product))->toBe('110.00');
});

it('returns null when the on-sale product has no usable history at all', function (): void {
    $product = onSaleProduct();

    expect(ResolvePriorPrice::forProduct($product))->toBeNull();
});

it('reads the loaded relation without extra queries', function (): void {
    $product = onSaleProduct(['discount_starts_at' => now()->subDays(2)]);
    historyRow($product, '90.00', 10);

    $loaded = Product::with(['priceHistory' => fn ($q) => $q->where('recorded_at', '>=', now()->subDays(40))])
        ->findOrFail($product->getKey());

    expect($loaded->relationLoaded('priceHistory'))->toBeTrue();

    DB::enableQueryLog();
    $result = ResolvePriorPrice::forProduct($loaded);
    DB::disableQueryLog();

    expect($result)->toBe('90.00')
        ->and(DB::getQueryLog())->toBeEmpty();
});
