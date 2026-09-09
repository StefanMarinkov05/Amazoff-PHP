<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Coupon;
use RuntimeException;

/**
 * A coupon was refused deletion because it still has redemptions or carts
 * referencing it.
 *
 * `coupon_redemptions.coupon_id` (required) and `carts.coupon_id`
 * (nullable, but no cascade) are both `constrained()` foreign keys, so the
 * database already refuses to delete a coupon either still points at — as
 * error 1451, a raw `QueryException`. This exception is what turns that
 * into a message an administrator can act on, thrown from inside a lock
 * rather than left to the database: `DeleteCoupon` re-reads both live
 * counts under `lockForUpdate()` before deciding, so a redemption or a
 * cart applying this coupon in the same instant cannot slip past a stale
 * count.
 */
class CouponCannotBeDeletedException extends RuntimeException
{
    public function __construct(string $message, public readonly Coupon $coupon)
    {
        parent::__construct($message);
    }

    public static function hasRedemptions(Coupon $coupon, int $redemptions): self
    {
        return new self(sprintf(
            'Coupon %s has %d redemption(s) and cannot be deleted.',
            $coupon->code,
            $redemptions,
        ), $coupon);
    }

    public static function hasCarts(Coupon $coupon, int $carts): self
    {
        return new self(sprintf(
            'Coupon %s is applied to %d cart(s) and cannot be deleted.',
            $coupon->code,
            $carts,
        ), $coupon);
    }
}
