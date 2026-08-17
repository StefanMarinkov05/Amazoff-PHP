<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Coupon;
use RuntimeException;

/**
 * A coupon failed one of `ApplyCoupon`'s or `RedeemCoupon`'s checks: not
 * active, outside its window, below the order minimum, out of scope for the
 * cart's lines, or one of the two usage caps already reached.
 *
 * One named constructor per refusal reason, matching
 * `InvalidCartQuantityException`'s shape — the message and whatever context
 * that case needs are decided at the throw site, one catch clause at each
 * caller. `misc/coupon-actions-plan.md`, decision 9.
 */
class CouponNotApplicableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Coupon $coupon,
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
}
