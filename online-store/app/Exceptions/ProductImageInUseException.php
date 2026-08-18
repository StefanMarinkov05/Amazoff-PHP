<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductImage;
use RuntimeException;

/**
 * An image was about to be removed while a variation still points at it.
 *
 * `product_variations.image_id` is a `NO ACTION` foreign key, so the database
 * refuses with error 1451 — a 500 rather than something an administrator can
 * act on. The action they can take is to repoint or remove those variations
 * first, which is what the message names.
 */
class ProductImageInUseException extends RuntimeException
{
    public function __construct(
        public readonly ProductImage $image,
        public readonly int $variations,
    ) {
        parent::__construct(sprintf(
            'Image %s is used by %d variation(s) and cannot be removed. '
            .'Point those variations at another image first.',
            $image->path,
            $variations,
        ));
    }
}
