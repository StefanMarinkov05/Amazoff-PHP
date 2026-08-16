<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\CartItem;

/**
 * Deletes a cart line. No invariant to protect — `cart_items` has nothing
 * downstream that depends on it existing, unlike a product variation, so
 * this is the one Action in this namespace with no non-obvious failure mode.
 */
final class RemoveFromCart
{
    public function handle(CartItem $item): void
    {
        $item->delete();
    }
}
