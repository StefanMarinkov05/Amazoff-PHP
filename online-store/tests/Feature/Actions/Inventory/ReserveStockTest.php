<?php

declare(strict_types=1);

use App\Actions\Inventory\ReleaseStock;
use App\Actions\Inventory\ReserveStock;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Inventory;
use App\Models\ProductVariation;
use App\Models\User;

/*
 * §20 — stock is reserved when an order reaches the appropriate stage and
 * released when payment fails, a session expires, or an order is cancelled.
 *
 * Actions are called directly here, with no HTTP and no request. That is the
 * property ADR-0007 is buying: the same class the storefront, the panel, and
 * the Stripe webhook call is testable by constructing it.
 */

function variationWithStock(int $current, int $reserved = 0): ProductVariation
{
    $variation = ProductVariation::factory()->create();

    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $current,
        'reserved_quantity' => $reserved,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    return $variation;
}

it('reserves stock and leaves current quantity untouched', function (): void {
    $variation = variationWithStock(10);

    $inventory = app(ReserveStock::class)->handle($variation, 3);

    // A reservation makes stock unsellable without removing it — the sale is
    // a separate movement.
    expect($inventory->current_quantity)->toBe(10)
        ->and($inventory->reserved_quantity)->toBe(3)
        ->and($inventory->available())->toBe(7);
});

it('writes a ledger row for every reservation', function (): void {
    $variation = variationWithStock(10);
    $actor = User::factory()->create();

    $inventory = app(ReserveStock::class)->handle($variation, 4, $actor);
    $movement = $inventory->inventoryMovements()->sole();

    // §20 forbids direct quantity writes; the ledger is what makes a wrong
    // total explainable afterwards.
    expect($movement->movement_type)->toBe(InventoryMovementType::OrderReservation)
        ->and($movement->quantity)->toBe(4)
        ->and($movement->created_by_id)->toBe($actor->getKey());
});

it('records a null actor for a system reservation', function (): void {
    $variation = variationWithStock(5);

    // ADR-0007: null actor means the application acting on its own behalf —
    // a webhook or a queued job. The column is nullable for exactly this.
    $inventory = app(ReserveStock::class)->handle($variation, 1);

    expect($inventory->inventoryMovements()->sole()->created_by_id)->toBeNull();
});

it('refuses to reserve more than is available', function (): void {
    $variation = variationWithStock(5, reserved: 3);

    expect(fn () => app(ReserveStock::class)->handle($variation, 3))
        ->toThrow(InsufficientStockException::class);

    // The failed attempt must leave nothing behind — no partial write, no
    // ledger row for a reservation that did not happen.
    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->reserved_quantity)->toBe(3)
        ->and($inventory->inventoryMovements()->count())->toBe(0);
});

it('reserves exactly up to the available quantity', function (): void {
    $variation = variationWithStock(5, reserved: 3);

    $inventory = app(ReserveStock::class)->handle($variation, 2);

    expect($inventory->available())->toBe(0)
        ->and($inventory->reserved_quantity)->toBe(5);
});

it('refuses to reserve against a variation removed from the catalogue', function (): void {
    $variation = variationWithStock(10);
    $variation->delete();

    // The stock row deliberately outlives the variation so §20's ledger
    // survives a removal, which means finding it proves nothing about whether
    // the variation is still sellable. A cart holds a variation from minutes
    // ago and an administrator can remove it in between.
    expect(fn () => app(ReserveStock::class)->handle($variation, 1))
        ->toThrow(RemovedFromCatalogueException::class);

    // Nothing held against a row nothing lists, and no ledger entry claiming
    // otherwise.
    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->inventoryMovements()->count())->toBe(0);
});

it('rejects a non-positive quantity', function (int $quantity): void {
    $variation = variationWithStock(10);

    expect(fn () => app(ReserveStock::class)->handle($variation, $quantity))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('releases reserved stock back to available', function (): void {
    $variation = variationWithStock(10, reserved: 6);

    $inventory = app(ReleaseStock::class)->handle($variation, 4);

    expect($inventory->reserved_quantity)->toBe(2)
        ->and($inventory->current_quantity)->toBe(10)
        ->and($inventory->available())->toBe(8);
});

it('records a release as a negative movement', function (): void {
    $variation = variationWithStock(10, reserved: 6);

    $inventory = app(ReleaseStock::class)->handle($variation, 4, null, 'payment failed');
    $movement = $inventory->inventoryMovements()->sole();

    // Negative because the ledger records direction, not event size. A ledger
    // where every row is positive cannot be summed.
    expect($movement->movement_type)->toBe(InventoryMovementType::ReservationRelease)
        ->and($movement->quantity)->toBe(-4)
        ->and($movement->note)->toBe('payment failed');
});

it('refuses to release more than is reserved', function (): void {
    $variation = variationWithStock(10, reserved: 2);

    expect(fn () => app(ReleaseStock::class)->handle($variation, 3))
        ->toThrow(InvalidArgumentException::class);
});

it('round-trips a reservation and a release to the starting state', function (): void {
    $variation = variationWithStock(10);

    app(ReserveStock::class)->handle($variation, 4);
    $inventory = app(ReleaseStock::class)->handle($variation, 4);

    expect($inventory->available())->toBe(10)
        ->and($inventory->reserved_quantity)->toBe(0)
        // Both movements survive. The ledger is append-only; a release does
        // not erase the reservation it undoes.
        ->and($inventory->inventoryMovements()->count())->toBe(2);
});
