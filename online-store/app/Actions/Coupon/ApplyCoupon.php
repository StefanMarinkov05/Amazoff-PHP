<?php

declare(strict_types=1);

namespace App\Actions\Coupon;

use App\Exceptions\CouponNotApplicableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Support\CalculateCartTotals;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use App\Support\ResolveVariationPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Attaches a coupon to a cart. Re-applying overwrites silently — last apply
 * wins, no explicit `RemoveCoupon` needed first (`misc/coupon-actions-plan.md`,
 * decision 7).
 *
 * Validates active, window, minimum order value, and scope against the
 * cart's *current* lines and prices — the same status `AddToCart`'s stock
 * check has relative to `ReserveStock`: advisory, not authoritative.
 * `RedeemCoupon` re-validates everything independently at checkout under a
 * lock this Action does not take.
 *
 * The per-customer usage cap is checked here only for a logged-in customer
 * — a guest cart carries no email yet to check it against. Guests apply a
 * coupon later in checkout, once email collection makes the check possible
 * (decision 6, a deliberate deviation from §10-12 recorded there).
 *
 * No `?User $actor`: a customer applying a coupon to their own cart holds
 * no permission to check, same reasoning as `AddToCart`.
 */
final class ApplyCoupon
{
    /**
     * @throws CouponNotApplicableException
     */
    public function handle(Cart $cart, string $code): Cart
    {
        /** @var Coupon $coupon */
        $coupon = Coupon::query()->where('code', $code)->firstOrFail();

        /** @var EloquentCollection<int, CartItem> $items */
        $items = $cart->cartItems()->with('productVariation.product')->get();
        $subtotal = CalculateCartTotals::forCart($cart)['subtotal'];

        // Throws on inactive / outside window / below minimum / out of scope.
        CalculateCouponDiscount::forLines($coupon, $this->linesFrom($items), $subtotal);

        if ($cart->user_id !== null) {
            $this->guardPerCustomerLimit($coupon, $cart->user_id);
        }

        $cart->update(['coupon_id' => $coupon->getKey()]);

        return $cart->refresh();
    }

    /**
     * @param  EloquentCollection<int, CartItem>  $items  With
     *                                                    `productVariation.product` eager-loaded.
     * @return Collection<int, CouponDiscountLine>
     */
    private function linesFrom(EloquentCollection $items): Collection
    {
        return $items
            ->filter(function (CartItem $item): bool {
                /** @var ProductVariation|null $variation */
                $variation = $item->productVariation;

                return $variation !== null && $variation->product !== null;
            })
            ->map(function (CartItem $item): CouponDiscountLine {
                /** @var ProductVariation $variation */
                $variation = $item->productVariation;

                /** @var Product $product */
                $product = $variation->product;

                $lineTotal = bcmul(ResolveVariationPrice::current($variation), (string) $item->quantity, 2);

                return new CouponDiscountLine(
                    productId: $product->getKey(),
                    productCategoryId: $product->product_category_id,
                    lineTotal: $lineTotal,
                    vatRate: (string) $product->vat_rate,
                );
            })
            ->values();
    }

    private function guardPerCustomerLimit(Coupon $coupon, int $userId): void
    {
        if ($coupon->usage_limit_per_customer === null) {
            return;
        }

        $redemptions = CouponRedemption::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $userId)
            ->count();

        if ($redemptions >= $coupon->usage_limit_per_customer) {
            throw CouponNotApplicableException::perCustomerLimitReached($coupon);
        }
    }
}
