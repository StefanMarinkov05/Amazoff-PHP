<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * A variation was about to be removed while stock is held against it.
 *
 * §20 reserves stock for an order that has not completed. Removing the
 * variation underneath that reservation strands it: the quantity stays
 * subtracted from `available()` on a row nothing lists any more, so the stock
 * is neither sellable nor visibly held, and only the ledger records why.
 *
 * A domain exception rather than `InvalidArgumentException`, because an
 * administrator can hit this legitimately and there is an action they can
 * take — fulfil or cancel the orders holding the stock, then remove the
 * variation.
 */
class VariationHasReservedStockException extends RuntimeException
{
    public function __construct(
        public readonly ProductVariation $variation,
        public readonly int $reserved,
    ) {
        parent::__construct(sprintf(
            'Cannot remove variation %s: %d unit(s) are reserved for open orders.',
            $variation->sku,
            $reserved,
        ));
    }
}
