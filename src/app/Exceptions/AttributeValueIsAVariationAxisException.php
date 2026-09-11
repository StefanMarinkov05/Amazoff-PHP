<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Attribute;
use App\Models\Product;
use RuntimeException;

/**
 * A product was given a descriptive attribute value for an attribute it
 * already uses as a variation axis.
 *
 * The two answer different questions about the same vocabulary — "what makes
 * this one different" (`attribute_value_product_variation`) versus "what is
 * this made of" (`attribute_value_product`) — and an attribute must not be
 * both for one product. A shirt that varies by Colour cannot also assert a
 * single product-wide Colour: the two contradict each other, and a
 * storefront filter reading both would return the product for a colour no
 * variation actually has.
 *
 * Neither pivot can see the other, so no constraint can express this.
 * ADR-0005's un-constrainable class; `SetProductAttributeValues` enforces it.
 */
class AttributeValueIsAVariationAxisException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly Attribute $attribute,
    ) {
        parent::__construct($attribute->is_variation_only
            ? sprintf(
                '"%s" is something the customer chooses between, so it belongs on a variation rather than on %s as a whole — one product cannot be two of them at once.',
                $attribute->name,
                $product->name,
            )
            : sprintf(
                '"%s" is one of %s\'s variation axes, so it cannot also be a product-wide value — set it on each variation instead.',
                $attribute->name,
                $product->name,
            ));
    }
}
