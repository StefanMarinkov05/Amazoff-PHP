<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\Order;

/**
 * Deletes every cart past its `expires_at`. `cart_items` cascades with it;
 * nothing else references a cart, since `orders.cart_id` is deliberately
 * unconstrained (see `CreateOrder`) — excluded here anyway, because a cart
 * that already produced an order is not abandoned, and deleting it would
 * orphan that link even without a foreign key to complain about it.
 *
 * Nothing in `app/` sets `expires_at` yet, so this currently has nothing to
 * act on — it exists ahead of that TTL policy, not because of it.
 */
final class ExpireCarts
{
    public function handle(): int
    {
        return Cart::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotIn('id', Order::query()->whereNotNull('cart_id')->select('cart_id'))
            ->delete();
    }
}
