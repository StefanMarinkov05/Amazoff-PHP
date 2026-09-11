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
 * Moves sold stock back to current, once an order returns.
 *
 * Called by `TransitionOrderStatus` for every order line on the move to
 * `OrderStatus::Returned`, and always credits `current_quantity` — the
 * return is received first, resellable by default. Whether the item is
 * actually damaged can only be answered by physically inspecting it, which
 * cannot happen at the same instant as the status transition, so it is a
 * separate `RecordDamage` call once someone has. See `write-rules/order.md`,
 * "Known gaps" for why that split is deliberate rather than incomplete.
 *
 * Returning more than was sold is a caller bug rather than a customer-facing
 * condition, mirroring `ReleaseStock`/`CompleteSale`, so it throws
 * `InvalidArgumentException` rather than a domain exception.
 *
 * Authorizes nothing, for the same reason its siblings do not. Locks
 * `inventories`. See `explanation/concurrency-and-locking.md`.
 */
final class RestockReturn
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    public function handle(
        ProductVariation $variation,
        int $quantity,
        ?User $actor,
        ?string $reason = null,
    ): Inventory {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Returned quantity must be at least 1.');
        }

        return DB::transaction(function () use ($variation, $quantity, $actor, $reason): Inventory {
            $inventory = Inventory::query()
                ->where('product_variation_id', $variation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventory->sold_quantity < $quantity) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot return %d of variation %s: only %d sold.',
                    $quantity,
                    $variation->sku,
                    $inventory->sold_quantity,
                ));
            }

            // No ordering hazard here, unlike CompleteSale: raising
            // current_quantity can never violate
            // chk_inventories_reserved_not_above_current, since reserved is
            // untouched by a return.
            $inventory->decrement('sold_quantity', $quantity);
            $inventory->increment('current_quantity', $quantity);
            $inventory->increment('returned_quantity', $quantity);

            // Positive — stock is entering the available pool, the opposite
            // direction from CompleteSale's negative CompletedSale.
            $this->recordMovement->handle(
                $inventory,
                InventoryMovementType::CustomerReturn,
                $quantity,
                $actor,
                $reason,
            );

            return $inventory->refresh();
        });
    }
}
