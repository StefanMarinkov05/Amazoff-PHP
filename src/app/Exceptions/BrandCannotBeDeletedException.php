<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Brand;
use RuntimeException;

/**
 * A brand was refused deletion because a product still references it.
 *
 * `products.brand_id` is a nullable `constrained()` foreign key with no
 * cascade, so the database already refuses to delete a brand any product
 * still points at — as error 1451, a raw `QueryException`. This exception
 * is what turns that into a message an administrator can act on, thrown
 * from inside a lock rather than left to the database: `DeleteBrand`
 * re-reads the live product count under `lockForUpdate()` before deciding,
 * so a product assigned to this brand in the same instant cannot slip past
 * a stale count.
 */
class BrandCannotBeDeletedException extends RuntimeException
{
    public function __construct(string $message, public readonly Brand $brand)
    {
        parent::__construct($message);
    }

    public static function hasProducts(Brand $brand, int $products): self
    {
        return new self(sprintf(
            'Brand %s has %d product(s) and cannot be deleted.',
            $brand->name,
            $products,
        ), $brand);
    }
}
