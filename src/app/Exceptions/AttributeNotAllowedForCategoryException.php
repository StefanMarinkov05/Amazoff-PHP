<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * A product's own "Variation axes" were set to an attribute that
 * `attribute_product_category` does not allow for the product's category (or
 * any of its ancestors) — "Shoe Size" on a perfume.
 *
 * `attribute_product` has a foreign key to `attributes` but cannot express
 * that the attribute's own allow-list must include the product's category —
 * the constraint spans two other tables neither side holds a column for.
 * Application invariant, in the class ADR-0005 records as un-constrainable.
 *
 * An attribute with no rows in `attribute_product_category` at all is
 * unrestricted, so this is reachable only for an attribute an admin
 * deliberately scoped — never for one of the ~40 seeded attributes that
 * predate this table and were never opted in.
 */
class AttributeNotAllowedForCategoryException extends RuntimeException
{
    /** @param  list<int>  $attributeIds  The offending ids, not the whole request. */
    public function __construct(
        public readonly Product $product,
        public readonly array $attributeIds,
    ) {
        parent::__construct(sprintf(
            'Attribute(s) %s are not allowed for %s\'s category.',
            implode(', ', $attributeIds),
            $product->name,
        ));
    }
}
