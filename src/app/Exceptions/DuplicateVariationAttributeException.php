<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Attribute;
use App\Models\ProductVariation;
use RuntimeException;

/**
 * A variation was given two values for the same attribute — two colours, two
 * volumes.
 *
 * `attribute_value_product_variation` has no column recording which attribute
 * a value belongs to, so nothing there can cap it at one per attribute; that
 * fact lives a table away, on `attribute_values.attribute_id`. Application
 * invariant, in the class ADR-0005 records as un-constrainable.
 */
class DuplicateVariationAttributeException extends RuntimeException
{
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly Attribute $attribute,
    ) {
        parent::__construct(sprintf(
            'Variation %s was given more than one value for "%s" — a variation can carry at most one value per attribute.',
            $variation->sku,
            $attribute->name,
        ));
    }
}
