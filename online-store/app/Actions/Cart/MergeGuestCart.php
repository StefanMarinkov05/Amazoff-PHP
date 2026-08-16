<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Support\Facades\DB;

/**
 * Folds a guest cart into the customer's cart on login, summing quantities
 * where both hold the same variation — `UNIQUE(cart_id, product_variation_id)`
 * forbids two rows, so a plain move would violate it on the second line.
 *
 * The summed quantity is **not** re-validated against current stock here.
 * Login is not a moment §11 requires availability to hold, and a customer
 * discovering at checkout that a merged line exceeds stock is `CreateOrder`'s
 * problem to raise, not this Action's to prevent — silently discarding part
 * of a merge would be a worse experience than a checkout-time message.
 *
 * The guest cart is deleted once merged, cascade or not: leaving an empty
 * cart with a stale `session_id` behind serves nothing.
 * reference/product-write-rules.md
 */
final class MergeGuestCart
{
    public function handle(Cart $guestCart, Cart $userCart): Cart
    {
        if ($guestCart->is($userCart)) {
            return $userCart;
        }

        DB::transaction(function () use ($guestCart, $userCart): void {
            /** @var iterable<CartItem> $guestItems */
            $guestItems = $guestCart->cartItems()->get();

            foreach ($guestItems as $guestItem) {
                /** @var CartItem|null $existing */
                $existing = $userCart->cartItems()
                    ->where('product_variation_id', $guestItem->product_variation_id)
                    ->first();

                if ($existing !== null) {
                    $existing->increment('quantity', $guestItem->quantity);
                } else {
                    $userCart->cartItems()->create([
                        'product_variation_id' => $guestItem->product_variation_id,
                        'quantity' => $guestItem->quantity,
                    ]);
                }
            }

            $guestCart->delete();
        });

        return $userCart->refresh();
    }
}
