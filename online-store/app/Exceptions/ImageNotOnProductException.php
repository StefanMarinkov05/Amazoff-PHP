<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * A variation's gallery was set to an image belonging to a different product.
 *
 * `product_image_product_variation` has a foreign key to each side but cannot
 * express that the two must share a product — the constraint spans a column
 * neither table holds. It is therefore an application invariant, in the class
 * ADR-0005 records as un-constrainable, and this is where it is enforced.
 *
 * Reachable without a caller bug: an administrator with 2 product tabs open
 * can submit image ids from the wrong one, and the resulting gallery would
 * show another product's photographs on a variation with no visible cause.
 */
class ImageNotOnProductException extends RuntimeException
{
    /** @param  list<int>  $imageIds  The offending ids, not the whole request. */
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly array $imageIds,
    ) {
        parent::__construct(sprintf(
            'Image(s) %s do not belong to variation %s\'s product and cannot be added to its gallery.',
            implode(', ', $imageIds),
            $variation->sku,
        ));
    }
}
