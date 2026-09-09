<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The Omnibus "prior price" for a product currently on sale: the lowest
 * effective price it was sold at in the 30 days before the reduction
 * (Directive (EU) 2019/2161; BG ЗЗП чл. 6б; ADR-0021).
 *
 * Reads `product_price_history` — one observation per price change plus a
 * daily snapshot (`RecordPriceObservation`). The reduction is taken to have
 * started at `discount_starts_at`, or now when the discount carries no start
 * date; the 30-day look-back ends there.
 *
 * A function, not an Action, because it writes nothing — the same shape as
 * `ResolveProductPrice` / `ResolveVariationPrice`. ADR-0014.
 *
 * Uses the `priceHistory` relation when it is already loaded (the catalogue
 * grid loads a 40-day slice to avoid one query per card) and queries
 * otherwise (the product page, where the full history is available and the
 * pre-window fallback below can reach older rows).
 *
 * Returns `null` when the product is not on sale, or when there is no price
 * history to draw a compliant figure from (a product with under 30 days of
 * history cannot honestly claim a prior price — which is itself the correct
 * outcome).
 */
final class ResolvePriorPrice
{
    private const LOOKBACK_DAYS = 30;

    public static function forProduct(Product $product): ?string
    {
        if (! ResolveProductPrice::current($product)->onSale) {
            return null;
        }

        $startsAt = $product->discount_starts_at;
        $reference = $startsAt !== null ? Carbon::parse((string) $startsAt) : Carbon::now();
        $windowStart = $reference->copy()->subDays(self::LOOKBACK_DAYS);

        if ($product->relationLoaded('priceHistory')) {
            $prices = $product->priceHistory
                ->filter(function (ProductPriceHistory $row) use ($windowStart, $reference): bool {
                    $recordedAt = $row->recorded_at;

                    return $recordedAt !== null
                        && $recordedAt->gte($windowStart)
                        && $recordedAt->lt($reference);
                })
                ->map(fn (ProductPriceHistory $row): string => $row->price)
                ->values()
                ->all();

            return $prices === [] ? null : self::lowest($prices);
        }

        $lowestInWindow = $product->priceHistory()
            ->where('recorded_at', '>=', $windowStart)
            ->where('recorded_at', '<', $reference)
            ->min('price');

        if (is_numeric($lowestInWindow)) {
            return (string) $lowestInWindow;
        }

        // The 30-day window has no observation of its own — fall back to the
        // price already in effect when the window opened, if we recorded one.
        $priorRow = $product->priceHistory()
            ->where('recorded_at', '<', $reference)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        return $priorRow instanceof ProductPriceHistory ? (string) $priorRow->price : null;
    }

    /**
     * @param  non-empty-array<int, numeric-string>  $prices
     */
    private static function lowest(array $prices): string
    {
        $lowest = null;

        foreach ($prices as $price) {
            $candidate = Money::of($price);

            if ($lowest === null || $candidate->isLessThan($lowest)) {
                $lowest = $candidate;
            }
        }

        return (string) $lowest;
    }
}
