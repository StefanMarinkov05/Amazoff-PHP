<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\Product;

/**
 * The daily sweep behind `products:snapshot-prices` (Omnibus prior-price
 * display, ADR-0021).
 *
 * Records one `product_price_history` row for every product — `force: true`,
 * so a product whose effective price did not change still gets a data point.
 * That daily cadence is what lets the prior-price query be "the lowest price
 * observed in the last 30 days" without replaying discount schedules, and is
 * what catches a scheduled discount window opening or closing with no admin
 * edit.
 *
 * Soft-deleted products are skipped — a product not in the catalogue shows no
 * price, so it has no prior price to maintain. Returns the number of rows
 * written, for the command to report.
 */
final class RecordProductPrices
{
    public function __construct(private readonly RecordPriceObservation $recordObservation) {}

    public function handle(): int
    {
        $written = 0;

        Product::query()
            ->select(['id', 'regular_price', 'discount_price', 'discount_starts_at', 'discount_ends_at'])
            ->chunkById(200, function ($products) use (&$written): void {
                foreach ($products as $product) {
                    $this->recordObservation->handle($product, force: true);
                    $written++;
                }
            });

        return $written;
    }
}
