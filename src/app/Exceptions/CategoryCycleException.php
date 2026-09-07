<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductCategory;
use RuntimeException;

/**
 * A category was reparented under itself or under one of its own
 * descendants, which would make `product_categories` cyclic.
 *
 * `parent_id` is a self-referencing foreign key and no foreign key can
 * express acyclicity — the database accepts A→B→A without complaint. One of
 * ADR-0005's un-constrainable invariants, and a live one rather than a
 * theoretical one: `ResolveCategoryFamily::ancestryOf()` and
 * `selfAndDescendantIds()` both walk this relationship, and a cycle turns
 * either into an unbounded loop. `ancestryOf()` carries its own iteration
 * guard for exactly that reason; this exception is what stops the cycle
 * being written in the first place.
 */
class CategoryCycleException extends RuntimeException
{
    public function __construct(
        public readonly ProductCategory $category,
        public readonly ProductCategory $proposedParent,
    ) {
        parent::__construct($category->is($proposedParent)
            ? sprintf('"%s" cannot be its own parent.', $category->name)
            : sprintf(
                '"%s" cannot be moved under "%s" — that category is one of its own subcategories.',
                $category->name,
                $proposedParent->name,
            ));
    }
}
