<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;

/**
 * Sets a cart line's quantity to an absolute value, re-validated the same
 * way `AddToCart` validates an add: §11's minimum and current stock.
 *
 * A separate Action from `AddToCart` rather than a shared "set quantity to
 * N" path, because the two have different failure semantics on the amount
 * requested — adding 3 to an existing 2 asks "is 5 legal", changing to 3
 * asks a question about 3 alone, and merging that distinction into one
 * signature is what `AddToCart`'s merge-by-summing exists to keep out of
 * this one.
 *
 * Both the variation and its product are read including trashed rows, so a
 * line pointing at a deleted one raises the same domain exception `AddToCart`
 * raises rather than a 404 from `firstOrFail()` or a null dereference on the
 * product. Deactivation is refused the same way, symmetric with `AddToCart` —
 * a line cannot be *changed* once its product or variation goes unavailable,
 * even though the line itself is left alone until the customer removes it.
 * reference/product-write-rules.md
 */
final class UpdateCartItemQuantity
{
    /**
     * @throws RemovedFromCatalogueException
     * @throws InvalidCartQuantityException
     * @throws InsufficientStockException
     */
    public function handle(CartItem $item, int $quantity): CartItem
    {
        /** @var ProductVariation $variation */
        $variation = ProductVariation::withTrashed()
            ->with(['product' => fn ($query) => $query->withTrashed(), 'inventory'])
            ->whereKey($item->product_variation_id)
            ->firstOrFail();

        /** @var Product|null $product */
        $product = $variation->product;

        if ($variation->trashed() || $product === null || $product->trashed()) {
            throw RemovedFromCatalogueException::variation($variation);
        }

        if (! $product->is_available || ! $variation->is_available) {
            throw RemovedFromCatalogueException::variation($variation);
        }

        if ($quantity < 1) {
            throw InvalidCartQuantityException::notPositive($product, $quantity);
        }

        if ($quantity < $product->min_order_quantity) {
            throw InvalidCartQuantityException::belowMinimumOrder($product, $quantity);
        }

        /** @var Inventory|null $inventory */
        $inventory = $variation->inventory;
        $available = $inventory?->available() ?? 0;

        if ($quantity > $available) {
            throw new InsufficientStockException($variation, $quantity, $available);
        }

        $item->update(['quantity' => $quantity]);

        return $item;
    }
}
