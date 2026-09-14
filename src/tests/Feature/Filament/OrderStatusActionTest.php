<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * §37 criterion 16 — "an employee can update order statuses" — had no panel
 * surface at all: TransitionOrderStatus, OrderPolicy::updateStatus(), and the
 * ADR-0004 matrix were all built and tested, but nothing in Filament called
 * any of them, and ViewOrder's only header action was an EditAction pointing
 * at a route OrderResource::getPages() never registers.
 *
 * What is ours here, and so what these prove: that the menu is generated from
 * the enum (not hand-written), that it authorizes per *target* status because
 * ADR-0011 makes cancel/refund administrator moves distinct from a warehouse
 * employee's routine advance, and that the write goes through the Action
 * rather than assigning ->status.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

it('advances an order through the panel, reaching TransitionOrderStatus', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('moveTo'.OrderStatus::Preparing->value, data: ['reason' => 'Picking started']);

    expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
});

it('writes the §19 history row rather than assigning the column', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('moveTo'.OrderStatus::Preparing->value, data: ['reason' => 'Picking started']);

    $history = $order->fresh()->orderStatusHistories()->latest()->first();

    expect($history)->not->toBeNull()
        ->and($history->new_status)->toBe(OrderStatus::Preparing)
        ->and($history->previous_status)->toBe(OrderStatus::Confirmed)
        ->and($history->reason)->toBe('Picking started');
});

/*
 * ADR-0011's split, at the panel. warehouse_employee holds
 * updateStatus_order but neither cancel_order nor refund_order, and
 * OrderPolicy::updateStatus() routes by target — so the same menu on the
 * same order offers different buttons to different staff, asserted here
 * against one order seen by both roles rather than two separate fixtures.
 *
 * These assert the menu only. That a warehouse employee *calling* the
 * transition anyway is refused is the Action's own territory, already
 * covered by TransitionOrderStatusTest's "denies cancel_order-less actor a
 * cancellation despite holding updateStatus_order" — a hidden button is not
 * security (CLAUDE.md), and this file does not restate the check that makes
 * it so.
 */
it('does not offer Cancel to a warehouse employee, and refuses it if attempted', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('moveTo'.OrderStatus::Cancelled->value)
        ->assertActionVisible('moveTo'.OrderStatus::Preparing->value);

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('offers Cancel to an administrator on the same order', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('moveTo'.OrderStatus::Cancelled->value);
});

/*
 * The menu is generated from OrderStatus::allowedTransitions(), so an
 * illegal move is never rendered. Delivered cannot go back to Preparing;
 * only Returned is reachable from it.
 */
it('offers only the transitions the ADR-0004 matrix allows', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $order = Order::factory()->create(['status' => OrderStatus::Delivered]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('moveTo'.OrderStatus::Returned->value)
        ->assertActionHidden('moveTo'.OrderStatus::Preparing->value)
        ->assertActionHidden('moveTo'.OrderStatus::Confirmed->value);
});

it('advances an order from the orders table too, not only the view page', function (): void {
    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail());

    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    Livewire::test(ListOrders::class)
        ->callTableAction(
            'moveTo'.OrderStatus::Preparing->value,
            $order,
            data: ['reason' => 'Picking started'],
        );

    expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
});
