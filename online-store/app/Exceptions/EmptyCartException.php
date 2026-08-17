<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Cart;
use RuntimeException;

/**
 * Checkout was attempted against a cart with no priceable lines — either no
 * `cart_items` rows at all, or every line points at a soft-deleted
 * variation/product `CalculateCartTotals` already excludes from the total.
 */
class EmptyCartException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Cart $cart,
    ) {
        parent::__construct($message);
    }

    public static function atCheckout(Cart $cart): self
    {
        return new self(sprintf('Cart %d has nothing to check out.', $cart->getKey()), $cart);
    }
}
