<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * RecordDamage was asked to damage more than is currently available.
 *
 * A domain exception rather than InvalidArgumentException, per ADR-0007:
 * unlike ReleaseStock/CompleteSale/RestockReturn — whose quantity always
 * comes from another Action, making "more than available" a caller bug —
 * RecordDamage is reached directly from a quantity a warehouse employee
 * types into the panel, where exceeding available stock is a mistake to
 * correct, not a programming error. See `RecordDamage`'s own docblock.
 */
class InsufficientStockToDamageException extends RuntimeException
{
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Cannot damage %d of variation %s: only %d available.',
            $requested,
            $variation->sku,
            $available,
        ));
    }
}
