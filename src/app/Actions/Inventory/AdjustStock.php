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
 * Raises or lowers a variation's stock on hand — a delivery arriving, or a
 * stocktake correcting the books against the shelf.
 *
 * The gap this fills: `initial_quantity` on `AddProductVariation` was the
 * only way stock ever entered the system, and it is create-only, so after a
 * variation existed nothing in the panel could change what it held.
 * `InventoryMovementType` already carried `NewDelivery` and
 * `ManualCorrection` — the schema anticipated this Action and it had never
 * been written.
 *
 * The other five inventory Actions all model a *consequence* — a sale, a
 * return, damage, a reservation and its release. This one models an
 * intention, which is why it takes a signed delta and a movement type
 * rather than inferring one: "+50, new delivery" and "-3, stocktake
 * correction" are different facts about the business, and collapsing them
 * into one type would make the ledger unable to answer "how much did we
 * actually receive this month?".
 *
 * Refuses a reduction that would leave `current_quantity` below what is
 * already reserved, for the same reason `RecordDamage` does: reserved stock
 * is promised to a specific order, and
 * `chk_inventories_reserved_not_above_current` would otherwise reject the
 * write as a 500 rather than this handled message. Freeing that stock means
 * releasing the reservation first, a decision about someone's order this
 * Action does not make on its own.
 *
 * §20 forbids writing a quantity behind the ledger's back, so every change
 * is a movement row and never a bare column write.
 *
 * Authorizes nothing — like its four siblings, the caller has already
 * authorized the correction this records. Locks `inventories`.
 * ADR-0008 · explanation/concurrency-and-locking.md
 */
final class AdjustStock
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    /**
     * @param  int  $delta  Signed. Positive receives stock, negative removes
     *                      it. Zero is refused rather than silently writing
     *                      a movement that records nothing.
     * @param  InventoryMovementType  $type  `NewDelivery` or
     *                                       `ManualCorrection`; any other is
     *                                       another Action's territory and
     *                                       is refused, so the ledger cannot
     *                                       grow a second writer of
     *                                       `CompletedSale` or `DamagedProduct`.
     */
    public function handle(
        ProductVariation $variation,
        int $delta,
        InventoryMovementType $type,
        ?User $actor,
        ?string $reason = null,
    ): Inventory {
        if ($delta === 0) {
            throw new InvalidArgumentException('Stock adjustment must be non-zero.');
        }

        if (! in_array($type, [InventoryMovementType::NewDelivery, InventoryMovementType::ManualCorrection], true)) {
            throw new InvalidArgumentException(sprintf(
                'AdjustStock records deliveries and manual corrections only; %s belongs to another Action.',
                $type->value,
            ));
        }

        return DB::transaction(function () use ($variation, $delta, $type, $actor, $reason): Inventory {
            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($delta < 0 && $inventory->available() < -$delta) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot remove %d from variation %s: only %d available (%d on hand, %d reserved).',
                    -$delta,
                    $variation->sku,
                    $inventory->available(),
                    $inventory->current_quantity,
                    $inventory->reserved_quantity,
                ));
            }

            $inventory->increment('current_quantity', $delta);

            $this->recordMovement->handle($inventory, $type, $delta, $actor, $reason);

            return $inventory->refresh();
        });
    }
}
