<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a variation to a cart, or increases the quantity if it is already
 * there — `UNIQUE(cart_id, product_variation_id)` allows one line per
 * variation, so a second add is a quantity change, not a second row.
 *
 * Validates against the variation's *current* state on every call: current
 * price (§11 — the cart never stores a price, so there is nothing to go
 * stale), current availability on both the product and the variation, the
 * product's `min_order_quantity`, and current stock. None of this is
 * re-validated at checkout by this Action — `CreateOrder` (slice 5)
 * re-validates independently, because a cart line can go stale between this
 * call and checkout.
 *
 * Deactivation is treated the same as soft-deletion: `RemovedFromCatalogueException`,
 * refusing the write. An existing line on a product deactivated after it was
 * added is left alone — this Action only guards what it writes, not what is
 * already in the cart. `RemoveFromCart` is how a customer clears a dead line,
 * same as for a soft-deleted product.
 *
 * Locks nothing. One owner is not one request — two tabs, a double-clicked
 * button — so the read-then-insert around
 * `UNIQUE(cart_id, product_variation_id)` is a real check-then-act window
 * whenever both sides start from "no existing line": per CLAUDE.md's rule for
 * idempotency, the fix is catching the violation and retrying as an update,
 * not a lock on a row that may not exist yet. The retry re-reads the
 * quantity, so it re-applies the same minimum and stock checks against the
 * row the loser actually collided with rather than the one it read.
 * Measured in `tests/Concurrency/AddToCartConcurrencyTest.php`.
 *
 * The stock check reads without a lock because it is advisory here: it stops
 * an obviously-doomed add early, but the number that matters is re-checked
 * under `lockForUpdate()` by `ReserveStock` at checkout, which is what
 * actually enforces it. reference/product-write-rules.md
 */
final class AddToCart
{
    /**
     * @throws RemovedFromCatalogueException
     * @throws InvalidCartQuantityException
     * @throws InsufficientStockException
     */
    public function handle(Cart $cart, ProductVariation $variation, int $quantity): CartItem
    {
        $live = ProductVariation::query()
            ->with('product')
            ->whereKey($variation->getKey())
            ->first();

        /** @var Product|null $product */
        $product = $live?->product;

        if ($live === null || $product === null || $product->trashed()) {
            throw RemovedFromCatalogueException::variation($variation);
        }

        if (! $product->is_available || ! $live->is_available) {
            throw RemovedFromCatalogueException::variation($live);
        }

        if ($quantity < 1) {
            throw InvalidCartQuantityException::notPositive($product, $quantity);
        }

        try {
            return $this->addOrIncrement($cart, $live, $product, $quantity);
        } catch (UniqueConstraintViolationException) {
            // The loser of the race: another request inserted the line between
            // this one's read and its write. Retrying re-reads the row that
            // now exists and folds into it, the same outcome a sequential
            // second call produces.
            return $this->addOrIncrement($cart, $live, $product, $quantity);
        }
    }

    private function addOrIncrement(Cart $cart, ProductVariation $live, Product $product, int $quantity): CartItem
    {
        return DB::transaction(function () use ($cart, $live, $product, $quantity): CartItem {
            /** @var CartItem|null $existing */
            $existing = $cart->cartItems()
                ->where('product_variation_id', $live->getKey())
                ->first();

            $currentQuantity = $existing === null ? 0 : $existing->quantity;
            $wanted = $quantity + $currentQuantity;

            if ($wanted < $product->min_order_quantity) {
                throw InvalidCartQuantityException::belowMinimumOrder($product, $wanted);
            }

            /** @var Inventory|null $inventory */
            $inventory = $live->inventory;
            $available = $inventory?->available() ?? 0;

            if ($wanted > $available) {
                throw new InsufficientStockException($live, $wanted, $available);
            }

            if ($existing !== null) {
                $existing->update(['quantity' => $wanted]);

                return $existing;
            }

            /** @var CartItem $item */
            $item = $cart->cartItems()->create([
                'product_variation_id' => $live->getKey(),
                'quantity' => $wanted,
            ]);

            return $item;
        });
    }
}
