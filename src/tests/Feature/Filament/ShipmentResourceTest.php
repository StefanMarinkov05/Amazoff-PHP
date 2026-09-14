<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * §37 criterion 15 — "a shipment can be created from an order".
 *
 * CreateShipment and TransitionShipmentStatus were built and tested with no
 * panel surface at all: warehouse_employee holds create_shipment and
 * update_shipment and had nothing to reach either through. Same shape the
 * inventory gap had.
 *
 * ShipmentFactory randomises `status`, `shipped_at`, and `delivered_at`, so
 * every test below pins what it asserts against — the trap
 * how-to/troubleshooting/data-and-factories.md documents for the product
 * factories applies here too.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

it('is reachable by warehouse_employee, the role §3.4 gives the shipment lifecycle', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail())
        ->get('/admin/shipments')
        ->assertOk();
});

it('is not reachable by content_editor', function (): void {
    $this->actingAs(User::where('email', 'editor@example.com')->firstOrFail())
        ->get('/admin/shipments')
        ->assertForbidden();
});

/*
 * The criterion itself: created *from an order*, on the order's own page,
 * rather than through a blank form on ShipmentResource that could pick an
 * order CreateShipment would then refuse.
 */
it('creates a shipment from an order, reaching CreateShipment', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $carrier = Carrier::factory()->create(['is_active' => true]);
    $order = Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('createShipment', data: ['carrier_id' => $carrier->getKey()]);

    $shipment = $order->fresh()->shipment;

    expect($shipment)->not->toBeNull()
        ->and($shipment->status)->toBe(ShipmentStatus::Pending)
        ->and($shipment->carrier_id)->toBe($carrier->getKey());
});

/*
 * §28's refusal reaching the user as a notification rather than a 500 —
 * ShipmentNotAllowedException is an App\Exceptions RuntimeException, so
 * ReportsDomainFailures converts it. What is ours is that the panel calls
 * the Action at all; the refusal rules themselves are CreateShipmentTest's.
 */
it('refuses a shipment for a cancelled order, and says so', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $carrier = Carrier::factory()->create(['is_active' => true]);
    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('createShipment', data: ['carrier_id' => $carrier->getKey()])
        ->assertNotified();

    expect($order->fresh()->shipment)->toBeNull();
});

it('hides the create action once an order already has a shipment', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $order = Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);
    Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'status' => ShipmentStatus::Pending,
    ]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('createShipment');
});

it('advances a shipment through the panel, reaching TransitionShipmentStatus', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $shipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Pending,
        'shipped_at' => null,
        'delivered_at' => null,
    ]);

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->callAction('moveTo'.ShipmentStatus::Shipped->value, data: ['description' => 'Handed to courier']);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('offers only the transitions the ShipmentStatus matrix allows', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $shipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Delivered,
        'shipped_at' => null,
        'delivered_at' => null,
    ]);

    // Delivered => [Returned] only.
    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertActionVisible('moveTo'.ShipmentStatus::Returned->value)
        ->assertActionHidden('moveTo'.ShipmentStatus::Shipped->value)
        ->assertActionHidden('moveTo'.ShipmentStatus::Cancelled->value);
});
