<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The cart's subtotal, VAT, and total, at current prices — never stored,
 * per §11 and §28 ("totals are always recalculated server-side"). A
 * function, not an Action: it writes nothing, so ADR-0007's threshold for a
 * command class does not apply.
 *
 * Delivery cost and coupon discount are not here — the first needs
 * `CalculateDeliveryPrice` (slice 8, needs an address), the second needs
 * `RedeemCoupon`'s validation (slice 4). Both compose on top of this rather
 * than in it, so the cart page can show a subtotal before either exists.
 *
 * Skips a line whose variation or product is gone — soft-deleted after the
 * line was added — rather than throwing. A stale line is left in the cart
 * for the customer to notice and remove (`reference/write-rules/cart.md`);
 * a total that crashes the page is worse than one that quietly excludes a
 * line nothing can be charged for yet.
 * `reference/write-rules/cart.md`
 */
final class CalculateCartTotals
{
    /**
     * @return array{subtotal: string, vat: string, total: string}
     */
    public static function forCart(Cart $cart): array
    {
        /** @var Collection<int, CartItem> $items */
        $items = $cart->cartItems()->with('productVariation.product')->get();

        $subtotal = '0.00';
        $vat = '0.00';

        foreach ($items as $item) {
            /** @var ProductVariation|null $variation */
            $variation = $item->productVariation;

            /** @var Product|null $product */
            $product = $variation?->product;

            if ($variation === null || $product === null) {
                continue;
            }

            $price = ResolveVariationPrice::current($variation);
            $lineTotal = bcmul($price, (string) $item->quantity, 2);

            $subtotal = bcadd($subtotal, $lineTotal, 2);

            // VAT is stored gross (CLAUDE.md), so it is extracted from the
            // line total rather than added on top: rate / (100 + rate).
            $vatRate = (string) $product->vat_rate;

            $lineVat = bcdiv(
                bcmul($lineTotal, $vatRate, 4),
                bcadd('100', $vatRate, 4),
                2,
            );

            $vat = bcadd($vat, $lineVat, 2);
        }

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $subtotal,
        ];
    }
}
