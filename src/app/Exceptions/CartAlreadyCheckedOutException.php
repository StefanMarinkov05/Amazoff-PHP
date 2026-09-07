<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Cart;
use InvalidArgumentException;
use RuntimeException;

/**
 * A second `CreateOrder` call against a cart that already produced an order.
 *
 * `orders.cart_id` is `UNIQUE`, nullable — the CLAUDE.md idempotency shape
 * (a constraint plus a caught violation, never check-then-act). The cart
 * itself carries no "already converted" flag; the order row it produced is
 * the flag, discovered by the second insert colliding rather than by reading
 * one first. `docs/reference/write-rules/order.md`, "Two actors at once" has
 * the concurrent case.
 */
class CartAlreadyCheckedOutException extends RuntimeException
{
    public function __construct(
        public readonly Cart $cart,
    ) {
        $cartKey = $cart->getKey();

        if (! is_scalar($cartKey)) {
            throw new InvalidArgumentException('Cart::getKey() returned a non-scalar value.');
        }

        parent::__construct(sprintf(
            'Cart %s has already been checked out.',
            $cartKey,
        ));
    }
}
