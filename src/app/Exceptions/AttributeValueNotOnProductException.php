<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * A variation's attribute values were set to a value belonging to an
 * attribute the product never declared as a variation axis.
 *
 * `attribute_value_product_variation` has a foreign key to `attribute_values`
 * but cannot express that the value's own attribute must be one the product
 * picked in `attribute_product` — the constraint spans a column neither table
 * holds. Application invariant, in the class ADR-0005 records as
 * un-constrainable.
 *
 * Reachable without a caller bug: an administrator can remove "Volume" from
 * a perfume's variation axes after a variation was already given a volume
 * value, or submit a value id copied from another product's tab.
 */
class AttributeValueNotOnProductException extends RuntimeException
{
    /** @param  list<int>  $attributeValueIds  The offending ids, not the whole request. */
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly array $attributeValueIds,
    ) {
        parent::__construct(sprintf(
            'Attribute value(s) %s belong to an attribute variation %s\'s product does not use.',
            implode(', ', $attributeValueIds),
            $variation->sku,
        ));
    }
}
