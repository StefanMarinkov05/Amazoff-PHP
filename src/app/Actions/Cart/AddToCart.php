<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\CartLimitExceededException;
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
use InvalidArgumentException;

/**
 * Adds a variation to a cart, or increases the line's quantity if one
 * already exists — `UNIQUE(cart_id, product_variation_id)` allows only one
 * row per variation.
 *
 * Re-checks availability, minimum quantity, and stock on every call, not
 * only on insert. None of it is re-validated at checkout by this Action;
 * `CreateOrder` does that independently since a line can go stale before
 * then. Price is never stored on the line, so there is nothing to
 * invalidate.
 *
 * Deactivation refuses a new write but leaves an existing line alone —
 * `RemoveFromCart` is how a customer clears it.
 *
 * Locks nothing: a caught `UNIQUE` violation retries as an update rather
 * than locking a row that may not exist yet. The stock check itself is
 * advisory — `ReserveStock` under `lockForUpdate()` at checkout is what
 * actually enforces it. `explanation/concurrency-and-locking.md`
 */
final class AddToCart
{
    /**
     * @throws RemovedFromCatalogueException
     * @throws InvalidCartQuantityException
     * @throws InsufficientStockException
     * @throws CartLimitExceededException
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
            // Loser of the race; see class docblock for why retrying is correct.
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

            $this->enforceCartLimits($cart, $live, $wanted, $existing !== null);

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

    /**
     * The cart-wide ceilings, checked inside the same transaction as the
     * write so two concurrent adds cannot both read a just-under count and
     * both commit.
     *
     * Counted from the database rather than from a passed-in figure, and
     * excluding the line being written when it already exists — otherwise
     * raising an existing line's quantity would count its old value and its
     * new one together.
     *
     * @throws CartLimitExceededException
     */
    private function enforceCartLimits(Cart $cart, ProductVariation $live, int $wanted, bool $lineExists): void
    {
        $maxLines = config('cart.max_lines');
        $maxUnits = config('cart.max_units');

        if (! is_int($maxLines) || ! is_int($maxUnits) || $maxLines < 1 || $maxUnits < 1) {
            throw new InvalidArgumentException(
                'config(cart.max_lines) and config(cart.max_units) must both be positive integers.'
            );
        }

        if (! $lineExists) {
            $lines = $cart->cartItems()->count() + 1;

            if ($lines > $maxLines) {
                throw CartLimitExceededException::tooManyLines($cart, $maxLines, $lines);
            }
        }

        $otherUnits = (int) $cart->cartItems()
            ->where('product_variation_id', '!=', $live->getKey())
            ->sum('quantity');

        if ($otherUnits + $wanted > $maxUnits) {
            throw CartLimitExceededException::tooManyUnits($cart, $maxUnits, $otherUnits + $wanted);
        }
    }
}
