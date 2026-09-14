<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\ProductVariation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Folds a guest cart into the customer's cart on login, summing quantities
 * where both hold the same variation — `UNIQUE(cart_id, product_variation_id)`
 * forbids two rows, so a plain move would violate it on the second line.
 *
 * The summed quantity is capped at the variation's currently available
 * stock — not merely deferred to checkout the way a single `AddToCart` call
 * is. Two independently-valid carts (a guest cart with 3 units, a customer
 * cart also with 3 units of the same variation, both legal when each was
 * built against 5 units of stock) can sum to a quantity neither cart alone
 * ever had — `AddToCart` checks `available()` on every call and would have
 * refused the 4th and 5th unit outright; a blind sum here bypasses that
 * check entirely. Capping, not throwing: `MergeCartOnAuthentication`'s own
 * rule is that a failed merge must never fail the login, so this degrades
 * silently to "as much as is actually available" rather than raising.
 * `CreateOrder`'s own `ReserveStock` would still refuse an over-quantity
 * line at checkout, but only after the customer has filled in address and
 * payment — this closes that gap where it is actually created, not just
 * downstream of it.
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

            $currentQuantity = $existing === null ? 0 : $existing->quantity;
            $summed = $currentQuantity + $quantity;
            $cap = $this->purchasableCap($variationId);
            // null means "the variation is gone, or carries no cap to apply"
            // — the catalogue is not consulted here (see the soft-delete
            // test below), so the raw sum survives unchanged, matching
            // AddToCart's own "deactivation leaves an existing line alone".
            $wanted = $cap === null ? $summed : min($summed, $cap);

            if ($existing !== null) {
                // Capped below 1: no purchasable quantity remains (either
                // truly out of stock, or what's left is under the product's
                // own minimum order quantity — a quantity AddToCart would
                // never have accepted either way). Deleting the line is the
                // correct representation — cart_items has no quantity-0
                // state, and RemoveFromCart is the customer's own path to
                // clear a line that no longer makes sense.
                if ($wanted < 1) {
                    $existing->delete();
                } elseif ($wanted !== $currentQuantity) {
                    $existing->update(['quantity' => $wanted]);
                }
            } elseif ($wanted >= 1) {
                $userCart->cartItems()->create([
                    'product_variation_id' => $variationId,
                    'quantity' => $wanted,
                ]);
            }
        });
    }

    /**
     * The largest quantity actually purchasable for a variation right now,
     * or `null` if the variation no longer exists (soft-deleted or gone) —
     * distinct from 0, which means "exists, but nothing to sell". Below the
     * product's own `min_order_quantity`, available stock is not a legal
     * quantity either — a customer cannot buy fewer than the minimum even
     * if 1 unit remains — so that also collapses to 0, the same outcome
     * `AddToCart` reaches by refusing the write outright.
     */
    private function purchasableCap(int $variationId): ?int
    {
        /** @var ProductVariation|null $variation */
        $variation = ProductVariation::query()->with('product')->find($variationId);

        if ($variation === null || $variation->product === null) {
            return null;
        }

        /** @var Inventory|null $inventory */
        $inventory = $variation->inventory;
        $available = $inventory?->available() ?? 0;

        $minimum = $variation->product->min_order_quantity;

        return $available >= $minimum ? $available : 0;
    }
}
