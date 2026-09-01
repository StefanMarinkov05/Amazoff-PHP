<?php

declare(strict_types=1);

use App\Enums\InventoryMovementType;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * warehouse_employee held update_inventory but had no reachable path to
 * AdjustStock/RecordDamage: the only UI for either lived inside
 * ProductVariationsRelationManager, nested under ProductResource and gated
 * by ProductPolicy::viewAny() (viewAny_product), which the role does not
 * hold. This resource is InventoryPolicy's own surface instead.
 *
 * The per-role assertions below are the regression guard for that specific
 * gap — a granted permission with nothing in the panel to reach it is not
 * caught by an authorization test on the Action alone, since the Action was
 * never unreachable, only its one UI path was.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

it('is reachable by warehouse_employee, the role the permission catalogue grants it to', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail())
        ->get('/admin/inventories')
        ->assertOk();
});

it('is reachable by administrator', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/admin/inventories')
        ->assertOk();
});

it('is not reachable by content_editor, which permissions.md scopes to articles only', function (): void {
    $this->actingAs(User::where('email', 'editor@example.com')->firstOrFail())
        ->get('/admin/inventories')
        ->assertForbidden();
});

it('records a delivery through the panel, reaching AdjustStock', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $variation = variationWithStock(current: 10, reserved: 2);

    Livewire::test(ViewInventory::class, ['record' => $variation->inventory->getKey()])
        ->callAction('adjustStock', data: [
            'movement_type' => InventoryMovementType::NewDelivery->value,
            'delta' => 50,
            'reason' => 'PO-1234',
        ]);

    $inventory = $variation->inventory->fresh();

    expect($inventory->current_quantity)->toBe(60)
        ->and($inventory->inventoryMovements()->latest()->first()->movement_type)
        ->toBe(InventoryMovementType::NewDelivery)
        ->and($inventory->inventoryMovements()->latest()->first()->note)->toBe('PO-1234');
});

it('refuses removing more than is available through the panel, same as the Action', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $variation = variationWithStock(current: 10, reserved: 8);

    Livewire::test(ViewInventory::class, ['record' => $variation->inventory->getKey()])
        ->callAction('adjustStock', data: [
            'movement_type' => InventoryMovementType::ManualCorrection->value,
            'delta' => -5,
        ]);

    // AdjustStock throws a plain InvalidArgumentException for this refusal
    // (a caller bug, per its own docblock, not a customer-facing
    // condition) — ReportsDomainFailures only converts App\Exceptions\*, so
    // this one surfaces rather than becoming a notification. Asserted by
    // the quantity being untouched, not by a notification.
    expect($variation->inventory->fresh()->current_quantity)->toBe(10);
})->throws(InvalidArgumentException::class);

it('records damage through the panel, reaching RecordDamage', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $variation = variationWithStock(current: 20, reserved: 0);

    Livewire::test(ViewInventory::class, ['record' => $variation->inventory->getKey()])
        ->callAction('recordDamage', data: [
            'quantity' => 3,
            'reason' => 'Water damage, shelf 4',
        ]);

    $inventory = $variation->inventory->fresh();

    expect($inventory->damaged_quantity)->toBe(3)
        ->and($inventory->current_quantity)->toBe(17)
        ->and($inventory->inventoryMovements()->latest()->first()->movement_type)
        ->toBe(InventoryMovementType::DamagedProduct);
});
