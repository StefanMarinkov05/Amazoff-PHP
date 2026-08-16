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
use Illuminate\Support\Facades\DB;

/**
 * Adds a variation to a cart, or increases the quantity if it is already
 * there — `UNIQUE(cart_id, product_variation_id)` allows one line per
 * variation, so a second add is a quantity change, not a second row.
 *
 * Validates against the variation's *current* state on every call: current
 * price (§11 — the cart never stores a price, so there is nothing to go
 * stale), current availability, the product's `min_order_quantity`, and
 * current stock. None of that is re-validated at checkout by this Action —
 * `CreateOrder` (slice 5) re-validates independently, because a cart line
 * can go stale between this call and checkout.
 *
 * Locks nothing. A cart is single-owner state — one session or one
 * `user_id` — so no second request can race this one for the same cart. The
 * stock check reads without a lock because it is advisory here: it stops an
 * obviously-doomed add early, but the number that matters is re-checked
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

        if ($quantity < 1) {
            throw InvalidCartQuantityException::notPositive($product, $quantity);
        }

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
