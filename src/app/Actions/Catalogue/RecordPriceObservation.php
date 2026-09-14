<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Support\Money;
use App\Support\Resolvers\ResolveProductPrice;

/**
 * Records a product's current **effective** selling price into
 * `product_price_history` — the raw material for the Omnibus prior-price
 * display (ADR-0021; BG ЗЗП чл. 6б).
 *
 * "Effective price" is `ResolveProductPrice::current()->current`: the
 * discounted price while the product's discount window is open, the regular
 * price otherwise. Recording *that* rather than the raw columns means the
 * "lowest in the last 30 days" query is a plain `MIN(price)` with no replay of
 * discount schedules.
 *
 * Two callers, two modes:
 *
 * - **`CreateProduct` / `UpdateProduct`** call `handle($product)` after saving.
 *   A row is written only when the effective price differs from the most
 *   recent observation — an edit that leaves the price untouched writes
 *   nothing.
 * - **`products:snapshot-prices`** (daily) calls `handle($product, force: true)`
 *   for every product, so a discount window that opened or closed on schedule
 *   with no admin edit still produces a daily data point. That the table then
 *   holds one row per product per day is intended — it is what makes "the
 *   lowest price *applied during* the 30 days" a direct query.
 *
 * Not wrapped in its own transaction: the Action callers already hold one, and
 * the command's per-product insert needs no atomicity beyond the single row.
 * Authorizes nothing — it observes, it does not mutate the product, and its
 * callers are already gated.
 */
final class RecordPriceObservation
{
    public function handle(Product $product, bool $force = false): ?ProductPriceHistory
    {
        $effective = ResolveProductPrice::current($product)->current;

        if (! $force) {
            $latest = $product->priceHistory()->orderByDesc('recorded_at')->orderByDesc('id')->first();

            if ($latest !== null && Money::of($latest->price)->equals(Money::of($effective))) {
                return null;
            }
        }

        /** @var ProductPriceHistory $row */
        $row = $product->priceHistory()->create([
            'price' => $effective,
            'recorded_at' => now(),
        ]);

        return $row;
    }
}
