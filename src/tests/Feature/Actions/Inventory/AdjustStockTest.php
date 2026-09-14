<?php

declare(strict_types=1);

use App\Actions\Inventory\AdjustStock;
use App\Enums\InventoryMovementType;
use App\Models\Inventory;

/*
 * The gap: initial_quantity on AddProductVariation was the only way stock
 * ever entered the system, and it is create-only. `NewDelivery` and
 * `ManualCorrection` had existed on InventoryMovementType since the schema
 * was drawn, unwritten by anything. Live report: a variation created with
 * opening stock had no way to receive more, or to be corrected, from the
 * panel afterward.
 *
 * variationWithStock() is defined in tests/Pest.php, shared with
 * ReserveStockTest, RecordDamageTest, CompleteSaleTest, and RestockReturnTest.
 */

it('raises current stock on a positive delta', function (): void {
    $variation = variationWithStock(current: 10);

    $inventory = app(AdjustStock::class)->handle($variation, 50, InventoryMovementType::NewDelivery, null);

    expect($inventory->current_quantity)->toBe(60);
});

it('lowers current stock on a negative delta', function (): void {
    $variation = variationWithStock(current: 10);

    $inventory = app(AdjustStock::class)->handle($variation, -3, InventoryMovementType::ManualCorrection, null);

    expect($inventory->current_quantity)->toBe(7);
});

it('records the movement with the signed delta and the given type', function (): void {
    $variation = variationWithStock(current: 10);

    $inventory = app(AdjustStock::class)->handle($variation, 50, InventoryMovementType::NewDelivery, null, 'PO-1234');
    $movement = $inventory->inventoryMovements()->sole();

    expect($movement->movement_type)->toBe(InventoryMovementType::NewDelivery)
        ->and($movement->quantity)->toBe(50)
        ->and($movement->note)->toBe('PO-1234');
});

it('rejects a zero delta', function (): void {
    $variation = variationWithStock(current: 10);

    expect(fn () => app(AdjustStock::class)->handle($variation, 0, InventoryMovementType::ManualCorrection, null))
        ->toThrow(InvalidArgumentException::class);

    expect(Inventory::first()->current_quantity)->toBe(10);
});

it('rejects a movement type that belongs to another Action', function (): void {
    // The ledger must not grow a second writer of CompletedSale or
    // DamagedProduct — those are CompleteSale's and RecordDamage's alone.
    $variation = variationWithStock(current: 10);

    expect(fn () => app(AdjustStock::class)->handle($variation, 5, InventoryMovementType::CompletedSale, null))
        ->toThrow(InvalidArgumentException::class);

    expect(Inventory::first()->current_quantity)->toBe(10);
});

it('refuses to reduce stock below what is already reserved', function (): void {
    // available() = current - reserved. Freeing reserved stock is
    // ReleaseStock's decision about someone's order, not this Action's.
    $variation = variationWithStock(current: 10, reserved: 8);

    expect(fn () => app(AdjustStock::class)->handle($variation, -3, InventoryMovementType::ManualCorrection, null))
        ->toThrow(InvalidArgumentException::class);

    expect(Inventory::first()->current_quantity)->toBe(10);
});

it('allows a reduction that leaves reserved stock untouched', function (): void {
    $variation = variationWithStock(current: 10, reserved: 4);

    $inventory = app(AdjustStock::class)->handle($variation, -6, InventoryMovementType::ManualCorrection, null);

    expect($inventory->current_quantity)->toBe(4)
        ->and($inventory->reserved_quantity)->toBe(4)
        ->and($inventory->available())->toBe(0);
});
