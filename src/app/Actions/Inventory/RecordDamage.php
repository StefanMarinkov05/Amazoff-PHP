<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockToDamageException;
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
 * Damaging more than is available throws `InsufficientStockToDamageException`
 * — unlike `ReleaseStock`/`CompleteSale`/`RestockReturn`, whose quantity
 * always comes from another Action (making "more than available" a caller
 * bug there), this one is reached directly from a quantity a warehouse
 * employee types into the panel (`ViewInventory`'s `recordDamage` action),
 * where exceeding available stock is a mistake to correct, not a
 * programming error. A non-positive quantity is still `InvalidArgumentException`
 * — the panel's own form already refuses that below 1, so reaching here is a
 * caller bug regardless of who the caller is.
 *
 * Authorizes nothing — like its three siblings, the caller is responsible
 * for having already authorized the correction this records.
 * Locks `inventories`. See `explanation/concurrency-and-locking.md`.
 */
final class RecordDamage
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    /**
     * @throws InsufficientStockToDamageException
     */
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
                throw new InsufficientStockToDamageException($variation, $quantity, $inventory->available());
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
