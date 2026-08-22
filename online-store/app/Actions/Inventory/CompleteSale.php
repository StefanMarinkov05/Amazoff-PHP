<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves reserved stock to sold, once an order actually ships. `ReserveStock`
 * moves stock into `reserved`; this is the third side, next to
 * `ReleaseStock` moving it back out. Called by `TransitionOrderStatus` for
 * every order line on the move to `OrderStatus::Shipped`. See ADR-0011 for
 * why the call lives inside that Action rather than a wrapper around it.
 *
 * Completing more than is reserved is a caller bug rather than a
 * customer-facing condition — mirrors `ReleaseStock`'s own reasoning — so it
 * throws `InvalidArgumentException` rather than a domain exception.
 *
 * Authorizes nothing, for the same reason `ReserveStock`/`ReleaseStock` do
 * not: the caller has already authorized the status change this records.
 * Locks `inventories`. See `explanation/concurrency-and-locking.md`.
 */
final class CompleteSale
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    public function handle(
        ProductVariation $variation,
        int $quantity,
        ?User $actor,
        ?string $reason = null,
    ): Inventory {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Completed quantity must be at least 1.');
        }

        return DB::transaction(function () use ($variation, $quantity, $actor, $reason): Inventory {
            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventory->reserved_quantity < $quantity) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot complete a sale of %d for variation %s: only %d reserved.',
                    $quantity,
                    $variation->sku,
                    $inventory->reserved_quantity,
                ));
            }

            // Order matters and is not visible to any static check.
            // chk_inventories_reserved_not_above_current is evaluated per
            // statement: with current == reserved (the entire held stock is
            // being sold), decrementing current first produces a row where
            // reserved momentarily exceeds current and the constraint
            // rejects the UPDATE mid-transaction. Reserved first is always
            // safe, because reserved can never exceed current to begin with.
            $inventory->decrement('reserved_quantity', $quantity);
            $inventory->decrement('current_quantity', $quantity);
            $inventory->increment('sold_quantity', $quantity);

            // Negative — the ledger records the direction of the change
            // (reserved leaving the pool), matching ReleaseStock's own
            // reasoning for a negative ReservationRelease.
            $this->recordMovement->handle(
                $inventory,
                InventoryMovementType::CompletedSale,
                -$quantity,
                $actor,
                $reason,
            );

            return $inventory->refresh();
        });
    }
}
