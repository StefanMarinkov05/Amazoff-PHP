<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use App\Models\ProductVariation;
use RuntimeException;

/**
 * An operation reached a catalogue row that has since been soft-deleted.
 *
 * Soft deletion is invisible to a model already loaded in memory. A cart holds
 * a variation from minutes ago, a Filament page holds a product from before
 * the request, and `deleted_at` was set in between — the object is still
 * there, still answers every accessor, and is no longer part of the catalogue.
 *
 * Both cases are reachable by ordinary use rather than by a caller bug, which
 * is why this is a domain exception: the storefront turns it into "no longer
 * available" and the panel into "this product has been deleted".
 */
class RemovedFromCatalogueException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Product|ProductVariation $record,
    ) {
        parent::__construct($message);
    }

    public static function variation(ProductVariation $variation): self
    {
        return new self(sprintf(
            'Variation %s has been removed from the catalogue.',
            $variation->sku,
        ), $variation);
    }

    public static function product(Product $product): self
    {
        return new self(sprintf(
            'Product %s has been removed from the catalogue.',
            $product->sku,
        ), $product);
    }
}
