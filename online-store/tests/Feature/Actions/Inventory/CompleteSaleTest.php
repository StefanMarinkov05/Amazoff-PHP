<?php

declare(strict_types=1);

use App\Actions\Inventory\CompleteSale;
use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\User;

/*
 * §20 — the counter nothing wrote to before this slice. `sold_quantity` and
 * `InventoryMovementType::CompletedSale` have existed since the schema was
 * drawn; `TransitionOrderStatus` is the first caller, on the move to
 * `OrderStatus::Shipped`. ADR-0011 has the reasoning for why this lives as
 * its own Action rather than inline in the transition.
 *
 * variationWithStock() is defined in tests/Pest.php, shared with
 * ReserveStockTest and RestockReturnTest.
 */

it('moves reserved stock to sold, reducing current quantity too', function (): void {
    $variation = variationWithStock(current: 10, reserved: 4);

    $inventory = app(CompleteSale::class)->handle($variation, 4, null);

    // Unlike ReserveStock, which only marks stock as held without removing
    // it, a completed sale means the goods physically left — current_quantity
    // falls alongside reserved_quantity.
    expect($inventory->current_quantity)->toBe(6)
        ->and($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->sold_quantity)->toBe(4)
        ->and($inventory->available())->toBe(6);
});

it('completes a partial sale, leaving the rest reserved', function (): void {
    $variation = variationWithStock(current: 10, reserved: 6);

    $inventory = app(CompleteSale::class)->handle($variation, 2, null);

    expect($inventory->reserved_quantity)->toBe(4)
        ->and($inventory->sold_quantity)->toBe(2)
        ->and($inventory->current_quantity)->toBe(8)
        ->and($inventory->available())->toBe(4);
});

it('writes a negative ledger row, the same direction as a release', function (): void {
    $variation = variationWithStock(current: 10, reserved: 4);
    $actor = User::factory()->create();

    $inventory = app(CompleteSale::class)->handle($variation, 4, $actor, 'order shipped');
    $movement = $inventory->inventoryMovements()->sole();

    expect($movement->movement_type)->toBe(InventoryMovementType::CompletedSale)
        ->and($movement->quantity)->toBe(-4)
        ->and($movement->created_by_id)->toBe($actor->getKey())
        ->and($movement->note)->toBe('order shipped');
});

it('records a null actor for a system-driven sale', function (): void {
    $variation = variationWithStock(current: 5, reserved: 5);

    $inventory = app(CompleteSale::class)->handle($variation, 5, null);

    expect($inventory->inventoryMovements()->sole()->created_by_id)->toBeNull();
});

/*
 * The constraint-ordering case: every unit of current stock is reserved and
 * all of it sells at once. chk_inventories_reserved_not_above_current is
 * evaluated per statement, so decrementing current_quantity before
 * reserved_quantity would produce reserved > current for one statement and
 * fail — this is the test that would catch that ordering bug; without it the
 * bug ships silently on every sale that does not happen to sell out.
 */
it('completes a sale that empties the entire reserved-equals-current stock', function (): void {
    $variation = variationWithStock(current: 3, reserved: 3);

    $inventory = app(CompleteSale::class)->handle($variation, 3, null);

    expect($inventory->current_quantity)->toBe(0)
        ->and($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->sold_quantity)->toBe(3)
        ->and($inventory->available())->toBe(0);
});

it('refuses to complete more than is reserved and writes nothing', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);

    expect(fn () => app(CompleteSale::class)->handle($variation, 3, null))
        ->toThrow(InvalidArgumentException::class);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->reserved_quantity)->toBe(2)
        ->and($inventory->sold_quantity)->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(0);
});

it('rejects a non-positive quantity', function (int $quantity): void {
    $variation = variationWithStock(current: 10, reserved: 5);

    expect(fn () => app(CompleteSale::class)->handle($variation, $quantity, null))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
