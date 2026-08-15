<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;

/**
 * Writes one row to the stock ledger.
 *
 * §20 requires stock to change through movements rather than direct quantity
 * writes, so every Action that touches a quantity column calls this in the
 * same transaction. The ledger is what makes a wrong total explainable after
 * the fact — without it, a drifting count has no history to read.
 *
 * This Action does not open a transaction of its own. It is never the whole
 * operation: a movement without the quantity change it describes is a lie,
 * so the caller owns the boundary and this joins it. Calling it directly,
 * outside an Action that changes the quantity it describes, is a mistake.
 *
 * Authorizes nothing — the caller has already authorized the operation this
 * records. Locks nothing; it joins the caller's transaction. See
 * `explanation/inventory.md`.
 */
final class RecordInventoryMovement
{
    /**
     * @param  int  $quantity  Signed. Positive adds to the quantity the
     *                         movement type describes, negative removes —
     *                         a reservation release is a negative
     *                         `ReservationRelease`, not a positive one.
     */
    public function handle(
        Inventory $inventory,
        InventoryMovementType $type,
        int $quantity,
        ?User $actor = null,
        ?string $note = null,
    ): InventoryMovement {
        // No policy check: the caller has already authorized the operation
        // this movement records, and a ledger row on its own is not
        // separately reachable by any actor.
        return $inventory->inventoryMovements()->create([
            'movement_type' => $type,
            'quantity' => $quantity,
            'created_by_id' => $actor?->id,
            'note' => $note,
        ]);
    }
}
