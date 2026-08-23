<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Folds a guest cart into the customer's cart on login, summing quantities
 * where both hold the same variation — `UNIQUE(cart_id, product_variation_id)`
 * forbids two rows, so a plain move would violate it on the second line.
 *
 * The summed quantity is not re-validated against stock; `CreateOrder` is
 * what raises that at checkout, same as `AddToCart`.
 *
 * Locks nothing on the first attempt, for the same reason `AddToCart` does
 * not — a caught `UNIQUE` violation retries as an update instead. Unlike
 * `AddToCart`'s retry, this one runs as a savepoint inside the merge's own
 * outer transaction (so one bad line rolls back the whole merge) and *locks*
 * on the retry: a savepoint rollback does not refresh the outer
 * `REPEATABLE READ` snapshot, so a plain re-read would collide again.
 * `explanation/concurrency-and-locking.md`
 *
 * The guest cart is deleted once merged; deleting it is idempotent, so two
 * concurrent merges of the same guest cart both succeed even though only one
 * row existed to delete.
 */
final class MergeGuestCart
{
    public function __construct(private readonly TouchCartExpiry $touchExpiry) {}

    public function handle(Cart $guestCart, Cart $userCart): Cart
    {
        if ($guestCart->is($userCart)) {
            return $userCart;
        }

        DB::transaction(function () use ($guestCart, $userCart): void {
            /** @var iterable<CartItem> $guestItems */
            $guestItems = $guestCart->cartItems()->get();

            foreach ($guestItems as $guestItem) {
                $this->mergeLine($userCart, $guestItem->product_variation_id, $guestItem->quantity);
            }

            $guestCart->delete();

            // Inside the transaction, and load-bearing: the surviving cart
            // now belongs to a user, so its guest expiry must be cleared or
            // carts:expire deletes a registered customer's cart a day later.
            $this->touchExpiry->handle($userCart);
        });

        return $userCart->refresh();
    }

    private function mergeLine(Cart $userCart, int $variationId, int $quantity): void
    {
        try {
            $this->applyLine($userCart, $variationId, $quantity, lock: false);
        } catch (UniqueConstraintViolationException) {
            // Loser of the race — a rival insert landed first. Locks this
            // retry; see the class docblock for why it must.
            $this->applyLine($userCart, $variationId, $quantity, lock: true);
        }
    }

    private function applyLine(Cart $userCart, int $variationId, int $quantity, bool $lock): void
    {
        DB::transaction(function () use ($userCart, $variationId, $quantity, $lock): void {
            $query = $userCart->cartItems()->where('product_variation_id', $variationId);

            /** @var CartItem|null $existing */
            $existing = $lock ? $query->lockForUpdate()->first() : $query->first();

            if ($existing !== null) {
                $existing->increment('quantity', $quantity);
            } else {
                $userCart->cartItems()->create([
                    'product_variation_id' => $variationId,
                    'quantity' => $quantity,
                ]);
            }
        });
    }
}
