<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Cart;
use RuntimeException;

/**
 * A write would take a cart past `config('cart.max_lines')` or
 * `config('cart.max_units')`.
 *
 * A `RuntimeException`, not an `InvalidArgumentException` like
 * `InvalidCartQuantityException`: the argument is perfectly valid in
 * isolation — adding one more unit of a product is an ordinary request —
 * and what refuses it is the state the cart is already in. That is a domain
 * rule a legal argument happened to violate, which is the line
 * `InvalidCartQuantityException`'s own docblock draws between the two
 * kinds.
 *
 * Why a cart needs a ceiling at all: every line costs a rendered row on the
 * cart page, the checkout summary and the confirmation email, an
 * `order_items` insert, and an `inventories` lock inside `CreateOrder`'s
 * transaction; every unit is stock `ReserveStock` holds out of everyone
 * else's reach until the order is paid or the unpaid-order sweep cancels it
 * (ADR-0022). Neither cost is bounded by the per-line checks that already
 * exist — `min_order_quantity` and available stock both look at one line
 * and say nothing about the cart as a whole.
 *
 * `docs/reference/write-rules/cart.md` has the outcomes.
 */
class CartLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Cart $cart,
        public readonly int $limit,
        public readonly int $requested,
    ) {
        parent::__construct($message);
    }

    public static function tooManyLines(Cart $cart, int $limit, int $requested): self
    {
        return new self(sprintf(
            'A basket may hold at most %d different items; this would make %d. Remove something first.',
            $limit,
            $requested,
        ), $cart, $limit, $requested);
    }

    public static function tooManyUnits(Cart $cart, int $limit, int $requested): self
    {
        return new self(sprintf(
            'A basket may hold at most %d items in total; this would make %d. Reduce a quantity first.',
            $limit,
            $requested,
        ), $cart, $limit, $requested);
    }
}
