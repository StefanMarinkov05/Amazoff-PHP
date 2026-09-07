<?php

declare(strict_types=1);

namespace App\Actions\Coupon;

use App\Exceptions\CouponNotApplicableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Support\CalculateCartTotals;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Attaches a coupon to a cart. Re-applying overwrites silently — last apply
 * wins, no explicit `RemoveCoupon` needed first: `carts.coupon_id` has no
 * uniqueness concern and nothing here reads the previous value to decide
 * anything.
 *
 * Validates active, window, minimum order value, and scope against the
 * cart's *current* lines and prices — the same status `AddToCart`'s stock
 * check has relative to `ReserveStock`: advisory, not authoritative.
 * `RedeemCoupon` re-validates everything independently at checkout under a
 * lock this Action does not take.
 *
 * The per-customer usage cap is checked here only for a logged-in customer
 * — a guest cart carries no email yet to check it against. A deliberate
 * deviation from §10-12, which lists discount codes among cart-level
 * actions: guests apply a coupon later in checkout instead, once email
 * collection makes the check possible.
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
        CalculateCouponDiscount::forLines($coupon, CouponDiscountLine::collectionFromCartItems($items), $subtotal);

        if ($cart->user_id !== null) {
            $this->guardPerCustomerLimit($coupon, $cart->user_id);
        }

        $cart->update(['coupon_id' => $coupon->getKey()]);

        return $cart->refresh();
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
