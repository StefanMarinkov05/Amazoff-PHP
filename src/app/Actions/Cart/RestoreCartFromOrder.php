<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a basket from an order's lines, for a customer who cancelled
 * their own checkout and should not have to find every item again.
 *
 * ## Which cart the lines land in
 *
 * The visitor's existing unspent cart if they have one, otherwise a new
 * one. They usually do have one: `CreateOrder` consumed the original, so
 * the next render of any cart-reading component already opened a fresh
 * empty cart through `ResolveCurrentCart::forVisitor()`. Creating another
 * here would leave that visitor with two unspent carts, and
 * `ResolveCurrentCart` hands back the first — showing them the empty one
 * while the restored lines sat in a row nothing reads.
 *
 * ## Why not the order's original cart
 *
 * `orders.cart_id` is UNIQUE and `ResolveCurrentCart` deliberately refuses
 * to hand back a cart that already produced an order — that exclusion is
 * load-bearing, not an optimisation. Without it a spent cart is returned to
 * the same session forever and `UNIQUE(orders.cart_id)` refuses every
 * subsequent checkout, permanently, for the life of that session (confirmed
 * live on 2026-09-05; see `ResolveCurrentCart`'s own docblock).
 *
 * So the cancelled order keeps its `cart_id` audit link intact and this
 * opens a *fresh* cart for the visitor, copying the lines across. The
 * customer sees their basket; §19's link from an order back to the basket
 * it came from survives; and the new cart can be checked out normally.
 *
 * ## What is not copied, and why nothing is re-validated
 *
 * A line whose variation is gone — force-deleted, leaving
 * `order_items.product_variation_id` null through `nullOnDelete()`, or
 * soft-deleted since the order was placed — is skipped. A `cart_items` row
 * pointing at a missing variation would violate the foreign key, and one
 * pointing at a soft-deleted variation is a line the customer cannot act on
 * anyway. Silently dropping a dead line is the precedent `CreateOrder` and
 * `CalculateCartTotals` already set for the same situation.
 *
 * Beyond existence, nothing is re-checked: not stock, not
 * `min_order_quantity`, not the current price. `MergeGuestCart` sets that
 * precedent explicitly — a restored line is revalidated by
 * `UpdateCartItemQuantity` on the next write to it, and authoritatively by
 * `CreateOrder` at the next checkout. Refusing to restore a line because
 * the item sold out in the meantime would lose the customer's basket to
 * tell them something the cart page already shows them.
 *
 * The coupon comes back too, as a plain `coupon_id` copy. `ApplyCoupon`'s
 * validation is advisory anyway and `RedeemCoupon` re-validates everything
 * under a lock at checkout, so carrying a since-expired code across costs
 * nothing but a refusal the customer can act on.
 *
 * A fresh cart has no lines, so the insert cannot collide with
 * `UNIQUE(cart_id, product_variation_id)` and needs none of
 * `MergeGuestCart`'s catch-and-retry. Two order lines pointing at the same
 * variation cannot exist either — `CreateOrder` writes one row per cart
 * line, and the cart had the same UNIQUE.
 *
 * No `?User $actor` and no policy, same as every other Cart Action: the
 * caller resolved the order through its own ownership check
 * (`CheckoutPage::order()` scopes to owner-or-session-claim), and a customer
 * restoring their own basket holds no permission to check.
 */
final class RestoreCartFromOrder
{
    /**
     * How the new cart is keyed is the caller's to state, because only it
     * knows the visitor: a signed-in customer's cart is keyed by `user_id`
     * and a guest's by `session_id`, exactly as `ResolveCurrentCart` keys
     * them. Passed as two typed parameters rather than one identity array
     * so every column written here is visible to static analysis.
     */
    public function handle(Order $order, ?int $userId, ?string $sessionId): Cart
    {
        return DB::transaction(function () use ($order, $userId, $sessionId): Cart {
            $identity = $userId !== null
                ? ['user_id' => $userId]
                : ['session_id' => $sessionId, 'user_id' => null];

            // Reuse the visitor's existing unspent cart if they already have
            // one, rather than opening a second. By the time Cancel is
            // pressed they usually do: `CreateOrder` consumed the original,
            // so the next render of any cart-reading component called
            // `ResolveCurrentCart::forVisitor()` and opened a fresh empty
            // one. Creating a third here would leave two unspent carts for
            // one visitor, and `ResolveCurrentCart` returns the *first* —
            // so the customer would be shown the empty one and the restored
            // lines would be invisible. Found by a component test, not by
            // inspection.
            /** @var Cart|null $existing */
            $existing = Cart::query()
                ->where($identity)
                ->whereDoesntHave('order')
                ->first();

            $couponId = $order->couponRedemptions()->value('coupon_id');

            if ($existing !== null) {
                $existing->update(['coupon_id' => $couponId]);
                $cart = $existing;
            } else {
                /** @var Cart $cart */
                $cart = Cart::query()->create([
                    ...$identity,
                    'coupon_id' => $couponId,
                    // Left null deliberately: TouchCartExpiry owns this
                    // column and the caller touches the cart after
                    // restoring it, as every other cart write does.
                    'expires_at' => null,
                ]);
            }

            /** @var iterable<int, OrderItem> $lines */
            $lines = $order->orderItems()
                ->whereNotNull('product_variation_id')
                ->get();

            foreach ($lines as $line) {
                $variationId = $line->product_variation_id;

                // Re-read rather than trusting the snapshot on the order
                // line: the variation may have been soft-deleted since, and
                // a cart line pointing at one is a line the customer can
                // neither buy nor fix.
                $live = ProductVariation::query()->whereKey($variationId)->first();

                if ($live === null) {
                    continue;
                }

                // updateOrCreate, not create: a reused cart may already
                // hold this variation (the customer added it again while
                // the order sat unpaid), and `UNIQUE(cart_id,
                // product_variation_id)` forbids a second row. The order's
                // quantity wins — it is what the customer was buying.
                $cart->cartItems()->updateOrCreate(
                    ['product_variation_id' => $variationId],
                    ['quantity' => $line->quantity],
                );
            }

            return $cart->refresh();
        });
    }
}
