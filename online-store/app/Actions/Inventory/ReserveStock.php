<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Inventory;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for an order that is not yet paid.
 *
 * §20: stock is reserved when an order reaches the appropriate stage and
 * released when payment fails, a session expires, or the order is cancelled.
 * Available quantity is current minus reserved, so a reservation makes stock
 * unsellable without removing it — the sale is recorded separately.
 *
 * This is the contested-state case CLAUDE.md names. Two customers checking
 * out simultaneously on the last item both read `available = 1`, both decide
 * they may proceed, and both write a reservation. `DB::transaction` alone
 * does not prevent it: the transaction makes the pair of writes atomic, it
 * does not stop the second reader seeing pre-write state. `lockForUpdate()`
 * is what serialises them — the second SELECT blocks until the first
 * transaction commits, then reads the updated row and correctly fails.
 *
 * The database is a backstop, not the mechanism. `chk_inventories_reserved_
 * not_above_current` rejects an over-reservation even if the lock were
 * wrong, but it does so as a QueryException — a 500, not a handled "out of
 * stock". Tests distinguish the two: hitting the constraint means the lock
 * failed.
 *
 * Authorizes nothing — a customer reserving their own cart holds no inventory
 * permission. Locks `inventories`. See
 * `explanation/concurrency-and-locking.md`.
 */
final class ReserveStock
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    /**
     * @throws InsufficientStockException
     */
    public function handle(
        ProductVariation $variation,
        int $quantity,
        ?User $actor = null,
    ): Inventory {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Reserved quantity must be at least 1.');
        }

        return DB::transaction(function () use ($variation, $quantity, $actor): Inventory {
            // lockForUpdate before reading the quantities, not after. A read
            // outside the lock is the race this whole class exists to close.
            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The stock row outlives the variation (§20's ledger), so finding
            // it proves nothing about whether the variation is still sellable.
            // Re-read rather than $variation->trashed(): an in-memory model
            // says nothing about a deleted_at written after it was loaded.
            $live = ProductVariation::query()->whereKey($variation->getKey())->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::variation($variation);
            }

            if ($inventory->available() < $quantity) {
                throw new InsufficientStockException(
                    $variation,
                    $quantity,
                    $inventory->available(),
                );
            }

            // increment(), never read-modify-write in PHP. If the lock above
            // is ever lost, this writes a value the CHECK constraint rejects
            // instead of a plausible one it accepts — a loud failure rather
            // than a silent oversell. See
            // explanation/concurrency-and-locking.md.
            $inventory->increment('reserved_quantity', $quantity);

            $this->recordMovement->handle(
                $inventory,
                InventoryMovementType::OrderReservation,
                $quantity,
                $actor,
            );

            return $inventory->refresh();
        });
    }
}
