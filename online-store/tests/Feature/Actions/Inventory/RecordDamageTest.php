<?php

declare(strict_types=1);

use App\Actions\Inventory\RecordDamage;
use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\User;

/*
 * §20 — the last counter nothing wrote to. `damaged_quantity` and
 * `InventoryMovementType::DamagedProduct` have existed since the schema was
 * drawn; general-purpose like ReserveStock/ReleaseStock, not part of
 * TransitionOrderStatus's effect table — see ADR-0011 and this Action's own
 * docblock for why a damaged return is a separate, manual step rather than a
 * branch inside RestockReturn.
 *
 * variationWithStock() is defined in tests/Pest.php, shared with
 * ReserveStockTest, CompleteSaleTest, and RestockReturnTest.
 */

it('moves current stock to damaged and leaves reserved untouched', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);

    $inventory = app(RecordDamage::class)->handle($variation, 3, null);

    expect($inventory->current_quantity)->toBe(7)
        ->and($inventory->damaged_quantity)->toBe(3)
        ->and($inventory->reserved_quantity)->toBe(2)
        ->and($inventory->available())->toBe(5);
});

it('records a partial damage, leaving the rest on hand', function (): void {
    $variation = variationWithStock(current: 10);

    $inventory = app(RecordDamage::class)->handle($variation, 4, null);

    expect($inventory->current_quantity)->toBe(6)
        ->and($inventory->damaged_quantity)->toBe(4);
});

it('writes a negative ledger row, the same direction as a release or a completed sale', function (): void {
    $variation = variationWithStock(current: 10);
    $actor = User::factory()->create();

    $inventory = app(RecordDamage::class)->handle($variation, 2, $actor, 'dropped in transit');
    $movement = $inventory->inventoryMovements()->sole();

    expect($movement->movement_type)->toBe(InventoryMovementType::DamagedProduct)
        ->and($movement->quantity)->toBe(-2)
        ->and($movement->created_by_id)->toBe($actor->getKey())
        ->and($movement->note)->toBe('dropped in transit');
});

it('records a null actor for a system-driven correction', function (): void {
    $variation = variationWithStock(current: 5);

    $inventory = app(RecordDamage::class)->handle($variation, 1, null);

    expect($inventory->inventoryMovements()->sole()->created_by_id)->toBeNull();
});

/*
 * The constraint-guard case: reserved stock cannot be damaged out from
 * underneath a reservation. Without this check, decrementing
 * current_quantity below reserved_quantity would either violate
 * chk_inventories_reserved_not_above_current (a 500) or, worse, succeed and
 * leave a reservation pointing at stock that no longer exists.
 */
it('refuses to damage reserved stock and writes nothing', function (): void {
    $variation = variationWithStock(current: 5, reserved: 5);

    expect(fn () => app(RecordDamage::class)->handle($variation, 1, null))
        ->toThrow(InvalidArgumentException::class);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->current_quantity)->toBe(5)
        ->and($inventory->damaged_quantity)->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(0);
});

it('allows damaging exactly up to the available quantity', function (): void {
    $variation = variationWithStock(current: 10, reserved: 6);

    $inventory = app(RecordDamage::class)->handle($variation, 4, null);

    expect($inventory->available())->toBe(0)
        ->and($inventory->current_quantity)->toBe(6)
        ->and($inventory->damaged_quantity)->toBe(4);
});

it('rejects a non-positive quantity', function (int $quantity): void {
    $variation = variationWithStock(current: 10);

    expect(fn () => app(RecordDamage::class)->handle($variation, $quantity, null))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
