<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Returns reserved stock to the available pool.
 *
 * §20 lists the four triggers: a payment fails, a payment session expires, an
 * order is cancelled, or an administrator cancels it. All four reach this
 * through their own Action rather than calling it directly.
 *
 * Locks for the same reason `ReserveStock` does. A release racing a
 * reservation on the same row would otherwise interleave two read-modify-write
 * pairs and lose one of them — the direction of the error differs, the cause
 * does not.
 *
 * Releasing more than is reserved is a caller bug rather than a customer-
 * facing condition, so it throws `InvalidArgumentException` rather than a
 * domain exception. There is no message a customer could act on, and
 * `chk_inventories_reserved_quantity_non_negative` would otherwise reject it
 * as a 500 further down.
 */
final class ReleaseStock
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    public function handle(
        ProductVariation $variation,
        int $quantity,
        ?User $actor = null,
        ?string $reason = null,
    ): Inventory {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Released quantity must be at least 1.');
        }

        return DB::transaction(function () use ($variation, $quantity, $actor, $reason): Inventory {
            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventory->reserved_quantity < $quantity) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot release %d of variation %s: only %d reserved.',
                    $quantity,
                    $variation->sku,
                    $inventory->reserved_quantity,
                ));
            }

            // decrement() for the same reason ReserveStock uses increment():
            // `SET reserved_quantity = reserved_quantity - n` is evaluated by
            // MySQL against committed state, so two concurrent releases cannot
            // lose one another the way two PHP-side subtractions would.
            $inventory->decrement('reserved_quantity', $quantity);

            // Negative, because the movement records the direction of the
            // change rather than the size of the event. A ledger where every
            // row is positive cannot be summed.
            $this->recordMovement->handle(
                $inventory,
                InventoryMovementType::ReservationRelease,
                -$quantity,
                $actor,
                $reason,
            );

            return $inventory->refresh();
        });
    }
}
