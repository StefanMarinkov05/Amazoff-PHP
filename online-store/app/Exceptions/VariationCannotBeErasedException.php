<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProductVariation;
use RuntimeException;

/**
 * A permanent delete was attempted on a variation something still depends on.
 *
 * Soft deletion hides a variation and keeps every row that explains it.
 * Erasing it destroys them, and three kinds of row must outlive it:
 *
 * - §20's ledger. `inventory_movements` is append-only and is the only record
 *   of how a quantity got where it is. A variation that ever held stock can
 *   never be erased without making a past total unexplainable.
 * - Order lines. `order_items.product_variation_id` is `ON DELETE SET NULL`,
 *   so an erase would succeed and silently null the link — §19 requires the
 *   history to survive, and a nulled reference is worse than a refusal
 *   because nothing reports it.
 * Cart lines are *not* a reason to refuse. A cart is transient, self-repairing
 * state with no historical value, so `ForceDeleteProductVariation` deletes
 * those rows instead — blocking an administrator on one would let a customer
 * pin a variation indefinitely by leaving a tab open.
 *
 * The narrow case that remains legal is the one this exists to allow: a
 * variation created by mistake, with an untouched stock row and no history.
 */
class VariationCannotBeErasedException extends RuntimeException
{
    public function __construct(string $message, public readonly ProductVariation $variation)
    {
        parent::__construct($message);
    }

    public static function hasLedger(ProductVariation $variation, int $movements): self
    {
        return new self(sprintf(
            'Variation %s has %d stock movement(s) and cannot be permanently deleted. '
            .'Remove it instead — it will be hidden and its history kept.',
            $variation->sku,
            $movements,
        ), $variation);
    }

    public static function isOrdered(ProductVariation $variation, int $orderItems): self
    {
        return new self(sprintf(
            'Variation %s appears on %d order line(s) and cannot be permanently deleted.',
            $variation->sku,
            $orderItems,
        ), $variation);
    }
}
