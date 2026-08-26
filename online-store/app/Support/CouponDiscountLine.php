<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * One line of `CalculateCouponDiscount`'s input, normalized so the same
 * bcmath works whether the source is a live `CartItem` (current price via
 * `ResolveVariationPrice`) or a placed order's `OrderItem` (`unit_price`
 * already snapshotted, §17). Neither model is referenced directly —
 * `CalculateCouponDiscount` only reads this shape.
 */
final class CouponDiscountLine
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $productCategoryId,
        public readonly string $lineTotal,
        public readonly string $vatRate,
    ) {}

    /**
     * Built by `ApplyCoupon` (advisory, cart still provisional) and
     * `CreateOrder` (to price the order row before `order_items` exist) —
     * one implementation of "what does this cart line cost right now",
     * not two kept in sync by hand.
     *
     * @param  EloquentCollection<int, CartItem>  $items  With
     *                                                    `productVariation.product` eager-loaded.
     * @return Collection<int, self>
     */
    public static function collectionFromCartItems(EloquentCollection $items): Collection
    {
        return $items
            ->filter(function (CartItem $item): bool {
                /** @var ProductVariation|null $variation */
                $variation = $item->productVariation;

                return $variation !== null && $variation->product !== null;
            })
            ->map(function (CartItem $item): self {
                /** @var ProductVariation $variation */
                $variation = $item->productVariation;

                /** @var Product $product */
                $product = $variation->product;

                $lineTotal = (string) Money::of(ResolveVariationPrice::current($variation))
                    ->multiply($item->quantity);

                return new self(
                    productId: $product->getKey(),
                    productCategoryId: $product->product_category_id,
                    lineTotal: $lineTotal,
                    vatRate: (string) $product->vat_rate,
                );
            })
            ->values();
    }
}
