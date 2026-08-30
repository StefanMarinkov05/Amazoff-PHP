<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * Two variations of the same product were given the exact same set of
 * attribute values — the same scent, the same volume, on two different SKUs.
 *
 * ADR-0005 names this explicitly as outside the database's reach ("set
 * equality across rows, such as 2 variations of 1 product carrying the same
 * attribute values") — no constraint can compare one pivot row set against
 * another's. Enforced here instead, the one place both sets are in hand at
 * once.
 */
class DuplicateVariationCombinationException extends RuntimeException
{
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly ProductVariation $conflictsWith,
    ) {
        parent::__construct(sprintf(
            'Variation %s would carry the same attribute values as %s — two variations of one product cannot be identical.',
            $variation->sku,
            $conflictsWith->sku,
        ));
    }
}
