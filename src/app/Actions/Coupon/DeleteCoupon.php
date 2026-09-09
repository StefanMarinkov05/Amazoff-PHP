<?php

declare(strict_types=1);

namespace App\Actions\Coupon;

use App\Exceptions\CouponCannotBeDeletedException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes a coupon, refusing while it still has redemptions or carts
 * applying it.
 *
 * `coupon_redemptions.coupon_id` and `carts.coupon_id` both already block
 * this at the database as error 1451, a raw `QueryException`. This Action
 * turns that into a message an administrator can act on, thrown from
 * inside a lock rather than left to the database: a redemption or a cart
 * applying this coupon in the same instant is either already visible to
 * the count or is itself blocked waiting on the lock — see
 * `explanation/concurrency-and-locking.md`.
 *
 * Authorizes `delete_coupon`. Locks `coupons`.
 */
final class DeleteCoupon
{
    /**
     * @throws CouponCannotBeDeletedException
     */
    public function handle(Coupon $coupon, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $coupon);
        }

        DB::transaction(function () use ($coupon): void {
            /** @var Coupon $locked */
            $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->getKey());

            $redemptions = $locked->couponRedemptions()->count();

            if ($redemptions > 0) {
                throw CouponCannotBeDeletedException::hasRedemptions($locked, $redemptions);
            }

            $carts = Cart::query()->where('coupon_id', $locked->getKey())->count();

            if ($carts > 0) {
                throw CouponCannotBeDeletedException::hasCarts($locked, $carts);
            }

            $locked->delete();
        });
    }
}
