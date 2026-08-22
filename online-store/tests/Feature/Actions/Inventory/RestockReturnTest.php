<?php

declare(strict_types=1);

use App\Actions\Inventory\RestockReturn;
use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\User;

/*
 * §20 — the other counter nothing wrote to before this slice.
 * `returned_quantity` and `InventoryMovementType::CustomerReturn` have
 * existed since the schema was drawn; `TransitionOrderStatus` is the first
 * caller, on the move to `OrderStatus::Returned`. Assumes the return is
 * resellable — a damaged return is a known gap, recorded in ADR-0011 and
 * this Action's own docblock, not closed here.
 *
 * variationWithStock() is defined in tests/Pest.php, shared with
 * ReserveStockTest and CompleteSaleTest.
 */

it('moves sold stock back to current and counts it as a return', function (): void {
    $variation = variationWithStock(current: 6, sold: 4);

    $inventory = app(RestockReturn::class)->handle($variation, 4, null);

    expect($inventory->current_quantity)->toBe(10)
        ->and($inventory->sold_quantity)->toBe(0)
        ->and($inventory->returned_quantity)->toBe(4)
        ->and($inventory->available())->toBe(10);
});

it('restocks a partial return, leaving the rest sold', function (): void {
    $variation = variationWithStock(current: 6, sold: 6);

    $inventory = app(RestockReturn::class)->handle($variation, 2, null);

    expect($inventory->sold_quantity)->toBe(4)
        ->and($inventory->current_quantity)->toBe(8)
        ->and($inventory->returned_quantity)->toBe(2);
});

it('leaves reserved_quantity untouched, unlike a sale or a release', function (): void {
    $variation = variationWithStock(current: 6, reserved: 3, sold: 4);

    $inventory = app(RestockReturn::class)->handle($variation, 4, null);

    // No ordering hazard exists here the way it does in CompleteSale: raising
    // current_quantity can never violate reserved <= current, since reserved
    // is untouched by a return.
    expect($inventory->reserved_quantity)->toBe(3)
        ->and($inventory->current_quantity)->toBe(10)
        ->and($inventory->available())->toBe(7);
});

it('writes a positive ledger row, the opposite direction from a completed sale', function (): void {
    $variation = variationWithStock(current: 6, sold: 4);
    $actor = User::factory()->create();

    $inventory = app(RestockReturn::class)->handle($variation, 4, $actor, 'customer returned item');
    $movement = $inventory->inventoryMovements()->sole();

    expect($movement->movement_type)->toBe(InventoryMovementType::CustomerReturn)
        ->and($movement->quantity)->toBe(4)
        ->and($movement->created_by_id)->toBe($actor->getKey())
        ->and($movement->note)->toBe('customer returned item');
});

it('records a null actor for a system-driven return', function (): void {
    $variation = variationWithStock(current: 5, sold: 5);

    $inventory = app(RestockReturn::class)->handle($variation, 5, null);

    expect($inventory->inventoryMovements()->sole()->created_by_id)->toBeNull();
});

it('refuses to return more than was sold and writes nothing', function (): void {
    $variation = variationWithStock(current: 6, sold: 2);

    expect(fn () => app(RestockReturn::class)->handle($variation, 3, null))
        ->toThrow(InvalidArgumentException::class);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->sold_quantity)->toBe(2)
        ->and($inventory->returned_quantity)->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(0);
});

it('rejects a non-positive quantity', function (int $quantity): void {
    $variation = variationWithStock(current: 6, sold: 4);

    expect(fn () => app(RestockReturn::class)->handle($variation, $quantity, null))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
