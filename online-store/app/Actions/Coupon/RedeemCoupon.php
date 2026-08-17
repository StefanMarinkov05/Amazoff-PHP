<?php

declare(strict_types=1);

namespace App\Actions\Coupon;

use App\Exceptions\CouponNotApplicableException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The authoritative coupon redemption, run inside `CreateOrder`'s
 * transaction. Re-validates everything `ApplyCoupon` did — nothing from the
 * cart's provisional state is trusted — plus both usage caps, now
 * enforceable for guests too since the order carries an email.
 * `misc/coupon-actions-plan.md`, decisions 3, 4, 5, 9.
 *
 * `coupons.times_used` does not exist; both caps are `COUNT`s over
 * `coupon_redemptions` taken under `lockForUpdate()` on the `coupons` row —
 * the row is locked before either count, so two concurrent redemptions
 * against the same coupon serialize rather than both reading a
 * pre-redemption count (decision 1).
 *
 * No `?User $actor`: there is no separate permission being checked here —
 * `CreateOrder`'s checkout request is already authorized, and
 * `coupon_redemptions.user_id`/`email_hash` both derive from the `Order`,
 * not from an actor (decision 4).
 *
 * Lock order: `products`, then `coupons`, then `inventories` (decision 5).
 * This Action only ever takes `coupons`, so it does not itself establish
 * the order — it is a constraint on whoever composes it with the other two.
 *
 * Same-order double redemption (a retried or double-submitted request) is
 * caught via `UNIQUE(coupon_id, order_id)` rather than checked first — the
 * database is the idempotency mechanism, per CLAUDE.md.
 */
final class RedeemCoupon
{
    /**
     * @throws CouponNotApplicableException
     */
    public function handle(Coupon $coupon, Order $order): CouponRedemption
    {
        return DB::transaction(function () use ($coupon, $order): CouponRedemption {
            /** @var Coupon $locked */
            $locked = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->firstOrFail();

            $lines = $this->linesFrom($order);
            $subtotal = (string) $order->subtotal_amount;

            $amounts = CalculateCouponDiscount::forLines($locked, $lines, $subtotal);

            $this->guardTotalLimit($locked);
            $this->guardPerCustomerLimit($locked, $order->email);

            $emailHash = $this->hashEmail($order->email);

            try {
                return $this->recordRedemption($locked, $order, $emailHash, $amounts['discount']);
            } catch (UniqueConstraintViolationException) {
                // This order already redeemed this coupon — a retry or a
                // double-submitted checkout. Not a second winner: return the
                // row the first attempt wrote.
                /** @var CouponRedemption $existing */
                $existing = CouponRedemption::query()
                    ->where('coupon_id', $locked->getKey())
                    ->where('order_id', $order->getKey())
                    ->firstOrFail();

                return $existing;
            }
        });
    }

    /**
     * @return Collection<int, CouponDiscountLine>
     */
    private function linesFrom(Order $order): Collection
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->orderItems()->with('product')->get();

        return $items->map(function (OrderItem $item): CouponDiscountLine {
            /** @var Product $product */
            $product = $item->product;

            return new CouponDiscountLine(
                productId: $item->product_id,
                productCategoryId: $product->product_category_id,
                lineTotal: (string) $item->line_total,
                vatRate: (string) $item->vat_rate,
            );
        });
    }

    private function guardTotalLimit(Coupon $coupon): void
    {
        if ($coupon->total_usage_limit === null) {
            return;
        }

        $redemptions = CouponRedemption::query()->where('coupon_id', $coupon->getKey())->count();

        if ($redemptions >= $coupon->total_usage_limit) {
            throw CouponNotApplicableException::totalLimitReached($coupon);
        }
    }

    private function guardPerCustomerLimit(Coupon $coupon, string $email): void
    {
        if ($coupon->usage_limit_per_customer === null) {
            return;
        }

        $emailHash = $this->hashEmail($email);

        $redemptions = CouponRedemption::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('email_hash', $emailHash)
            ->count();

        if ($redemptions >= $coupon->usage_limit_per_customer) {
            throw CouponNotApplicableException::perCustomerLimitReached($coupon);
        }
    }

    /**
     * sha256(pepper . normalized email), never the plaintext email itself.
     * `explanation/gdpr.md`, "Coupon limits without storing an email".
     */
    private function hashEmail(string $email): string
    {
        $pepper = (string) config('coupons.email_pepper');

        return hash('sha256', $pepper.mb_strtolower(trim($email)));
    }

    private function recordRedemption(Coupon $coupon, Order $order, string $emailHash, string $discount): CouponRedemption
    {
        /** @var CouponRedemption $redemption */
        $redemption = CouponRedemption::query()->create([
            'coupon_id' => $coupon->getKey(),
            'order_id' => $order->getKey(),
            'user_id' => $order->user_id,
            'email_hash' => $emailHash,
            'discount_amount' => $discount,
        ]);

        return $redemption;
    }
}
