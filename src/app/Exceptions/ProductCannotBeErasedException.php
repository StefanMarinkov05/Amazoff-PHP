<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * A permanent delete was attempted on a product something still depends on.
 *
 * The variation-level counterpart is `VariationCannotBeErasedException`, and
 * the reasoning is the same one level up: soft deletion withdraws a product
 * and keeps every row that explains it, while erasing destroys them.
 *
 * Order lines are the case worth stating. `order_items.product_id` is
 * `ON DELETE SET NULL`, so the database would accept the erase and silently
 * null the reference — §19 requires that history to survive, and a success
 * nobody is told about is worse than a refusal. Reviews and wishlist items are
 * `NO ACTION`, so without these checks they would surface as error 1451.
 */
class ProductCannotBeErasedException extends RuntimeException
{
    public function __construct(string $message, public readonly Product $product)
    {
        parent::__construct($message);
    }

    public static function isOrdered(Product $product, int $orderItems): self
    {
        return new self(sprintf(
            'Product %s appears on %d order line(s) and cannot be permanently deleted. '
            .'Delete it instead — it will leave the catalogue and its history will be kept.',
            $product->sku,
            $orderItems,
        ), $product);
    }

    public static function isReviewed(Product $product, int $reviews): self
    {
        return new self(sprintf(
            'Product %s has %d review(s) and cannot be permanently deleted.',
            $product->sku,
            $reviews,
        ), $product);
    }

    public static function isWishlisted(Product $product, int $wishlisted): self
    {
        return new self(sprintf(
            'Product %s is on %d wishlist(s) and cannot be permanently deleted.',
            $product->sku,
            $wishlisted,
        ), $product);
    }
}
