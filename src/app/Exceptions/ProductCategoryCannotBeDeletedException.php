<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductCategory;
use RuntimeException;

/**
 * A category was refused deletion because something still depends on it.
 *
 * `product_categories.parent_id` is a self-referencing `constrained()`
 * foreign key with no cascade, so the database already refuses to delete a
 * category any child still references — as error 1451, a raw
 * `QueryException`. This exception is what turns that into a message an
 * administrator can act on, thrown from inside a lock rather than left to
 * the database: `DeleteProductCategory` re-reads live children and products
 * under `lockForUpdate()` before deciding, so a concurrent category created
 * underneath this one, or a concurrent product assigned to it, cannot slip
 * past a stale count.
 */
class ProductCategoryCannotBeDeletedException extends RuntimeException
{
    public function __construct(string $message, public readonly ProductCategory $category)
    {
        parent::__construct($message);
    }

    public static function hasChildren(ProductCategory $category, int $children): self
    {
        return new self(sprintf(
            'Category %s has %d subcategory row(s) and cannot be deleted.',
            $category->name,
            $children,
        ), $category);
    }

    public static function hasProducts(ProductCategory $category, int $products): self
    {
        return new self(sprintf(
            'Category %s has %d product(s) and cannot be deleted.',
            $category->name,
            $products,
        ), $category);
    }
}
