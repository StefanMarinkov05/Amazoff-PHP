<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * A reservation asked for more than is available.
 *
 * A domain exception rather than a false return, per ADR-0007: the caller
 * cannot ignore it by accident, and the message the customer sees is the
 * caller's decision — the storefront says "only 2 left", the panel says
 * something an employee can act on.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Cannot reserve %d of variation %s: %d available.',
            $requested,
            $variation->sku,
            $available,
        ));
    }
}
