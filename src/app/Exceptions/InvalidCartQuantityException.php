<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use InvalidArgumentException;

/**
 * A cart quantity failed §11's rules: at least 1, at least the product's
 * `min_order_quantity`, at most what is available.
 *
 * Extends `InvalidArgumentException`, not `RuntimeException` like the
 * catalogue exceptions — the caller passed a bad argument, not a domain rule
 * a legal argument happened to violate. reference/write-rules/product.md
 */
class InvalidCartQuantityException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly Product $product,
        public readonly int $requested,
    ) {
        parent::__construct($message);
    }

    public static function belowMinimumOrder(Product $product, int $requested): self
    {
        return new self(sprintf(
            '%s has a minimum order quantity of %d; %d was requested.',
            $product->name,
            $product->min_order_quantity,
            $requested,
        ), $product, $requested);
    }

    public static function notPositive(Product $product, int $requested): self
    {
        return new self(sprintf(
            'Quantity must be at least 1; %d was requested for %s.',
            $requested,
            $product->name,
        ), $product, $requested);
    }
}
