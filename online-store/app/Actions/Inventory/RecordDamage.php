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
 * Moves current stock to damaged, for units found unsellable on the shelf.
 *
 * General-purpose, like `ReserveStock`/`ReleaseStock` — a warehouse employee
 * marking N units damaged, independent of any specific order, not composed
 * by `TransitionOrderStatus`. Also how a damaged *return* is corrected:
 * `RestockReturn` always credits a return to `current_quantity` first, and
 * this Action moves it on to `damaged_quantity` afterward, once someone has
 * actually inspected it. See `write-rules/order.md`, "Known gaps" and
 * ADR-0011.
 *
 * Damaging more than is currently on hand is a caller bug rather than a
 * customer-facing condition, mirroring `ReleaseStock`/`CompleteSale`/
 * `RestockReturn`, so it throws `InvalidArgumentException` rather than a
 * domain exception.
 *
 * Authorizes nothing — like its three siblings, the caller is responsible
 * for having already authorized the correction this records.
 * Locks `inventories`. See `explanation/concurrency-and-locking.md`.
 */
final class RecordDamage
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    public function handle(
        ProductVariation $variation,
        int $quantity,
        ?User $actor,
        ?string $reason = null,
    ): Inventory {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Damaged quantity must be at least 1.');
        }

        return DB::transaction(function () use ($variation, $quantity, $actor, $reason): Inventory {
            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Guards available() (current minus reserved), not current_
            // quantity alone — reserved stock is promised to a specific
            // order, and decrementing current underneath a reservation
            // would push reserved above current, which
            // chk_inventories_reserved_not_above_current then rejects as a
            // 500 rather than this handled refusal. Damaging reserved stock
            // means releasing that reservation first (ReleaseStock), a
            // decision about someone's order this Action does not make on
            // its own.
            if ($inventory->available() < $quantity) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot damage %d of variation %s: only %d available (%d on hand, %d reserved).',
                    $quantity,
                    $variation->sku,
                    $inventory->available(),
                    $inventory->current_quantity,
                    $inventory->reserved_quantity,
                ));
            }

            $inventory->decrement('current_quantity', $quantity);
            $inventory->increment('damaged_quantity', $quantity);

            // Negative — leaving the sellable pool, the same direction
            // CompleteSale and ReleaseStock use for the same reason.
            $this->recordMovement->handle(
                $inventory,
                InventoryMovementType::DamagedProduct,
                -$quantity,
                $actor,
                $reason,
            );

            return $inventory->refresh();
        });
    }
}
