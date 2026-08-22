<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Coupon;
use RuntimeException;

/**
 * A coupon failed one of `ApplyCoupon`'s or `RedeemCoupon`'s checks: not
 * active, outside its window, below the order minimum, out of scope for the
 * cart's lines, one of the two usage caps already reached, or gone entirely.
 *
 * One named constructor per refusal reason, matching
 * `InvalidCartQuantityException`'s shape — the message and whatever context
 * that case needs are decided at the throw site, one catch clause at each
 * caller. `misc/coupon-actions-plan.md`, decision 9.
 *
 * `coupon` is nullable for exactly one reason: `noLongerExists()` is thrown
 * when there is no row left to carry — a hard-deleted `Coupon` has nothing
 * to attach. Every other named constructor still passes one.
 */
class CouponNotApplicableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Coupon $coupon,
    ) {
        parent::__construct($message);
    }

    public static function inactive(Coupon $coupon): self
    {
        return new self(sprintf('Coupon %s is not active.', $coupon->code), $coupon);
    }

    public static function outsideWindow(Coupon $coupon): self
    {
        return new self(sprintf('Coupon %s is not valid at this time.', $coupon->code), $coupon);
    }

    public static function belowMinimum(Coupon $coupon, string $subtotal): self
    {
        return new self(sprintf(
            'Coupon %s requires a minimum order of %s; the order is %s.',
            $coupon->code,
            (string) $coupon->minimum_order_value,
            $subtotal,
        ), $coupon);
    }

    public static function outOfScope(Coupon $coupon): self
    {
        return new self(sprintf('Coupon %s does not apply to any item in this order.', $coupon->code), $coupon);
    }

    public static function totalLimitReached(Coupon $coupon): self
    {
        return new self(sprintf('Coupon %s has reached its total usage limit.', $coupon->code), $coupon);
    }

    public static function perCustomerLimitReached(Coupon $coupon): self
    {
        return new self(sprintf('Coupon %s has already been used the maximum number of times allowed per customer.', $coupon->code), $coupon);
    }

    /**
     * `Coupon::query()->find($couponId)` returned null — the row a cart's
     * `coupon_id` pointed at is gone. Every other case here still has the
     * `Coupon` in hand to build a message from; this one has only the id
     * that used to matter, so the message names it instead.
     *
     * Defence in depth more than an open door: `carts.coupon_id` is a
     * `constrained()` foreign key with no cascade, so an ordinary
     * `$coupon->forceDelete()` while any cart still applies it fails at the
     * database with error 1451 before `CreateOrder` ever sees a dangling
     * reference. Reachable only if that constraint is later relaxed, or a
     * coupon is removed by a path that bypasses Eloquent entirely.
     */
    public static function noLongerExists(int $couponId): self
    {
        return new self(sprintf('The applied coupon (id %d) no longer exists.', $couponId), null);
    }
}
