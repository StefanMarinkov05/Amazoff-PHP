<?php

declare(strict_types=1);

use App\Actions\Shipment\CreateShipment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShipmentNotAllowedException;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * §28's rule that a shipment cannot be created for an invalid order.
 *
 * The rule most easily got backwards is the unpaid one: a cash-on-delivery
 * order ships BEFORE it is paid - that is the whole method - so the check
 * reads orders.payment_method first. Deciding on payment status alone would
 * refuse every legitimate COD shipment.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

function activeCarrier(): Carrier
{
    return Carrier::factory()->create(['is_active' => true]);
}

it('ships a prepaid order with no cod_amount', function (): void {
    // Prepaid: the courier collects nothing. A cod_amount here would have the
    // customer charged twice, which is what the nullability prevents.
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'payment_method' => PaymentMethod::Stripe,
        'total_amount' => '120.00',
    ]);

    $shipment = app(CreateShipment::class)->handle($order, activeCarrier(), null);

    expect($shipment->status)->toBe(ShipmentStatus::Pending)
        ->and($shipment->cod_amount)->toBeNull();
});

it('ships an unpaid cash-on-delivery order and carries the total to the courier', function (): void {
    // Status New means unpaid. A COD order in that state must still ship, and
    // the courier must be told what to collect.
    $order = Order::factory()->create([
        'status' => OrderStatus::New,
        'payment_method' => PaymentMethod::CashOnDelivery,
        'total_amount' => '64.50',
    ]);

    $shipment = app(CreateShipment::class)->handle($order, activeCarrier(), null);

    expect($shipment->status)->toBe(ShipmentStatus::Pending)
        ->and((string) $shipment->cod_amount)->toBe('64.50');
});

it('refuses an unpaid prepaid order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::New,
        'payment_method' => PaymentMethod::Stripe,
    ]);

    expect(fn () => app(CreateShipment::class)->handle($order, activeCarrier(), null))
        ->toThrow(ShipmentNotAllowedException::class);

    expect(Shipment::where('order_id', $order->getKey())->exists())->toBeFalse();
});

it('refuses a cancelled order whatever the payment method', function (): void {
    // Cancelled has no path back, so even COD's ship-before-paid exemption
    // must not reach past it.
    $cod = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);

    expect(fn () => app(CreateShipment::class)->handle($cod, activeCarrier(), null))
        ->toThrow(ShipmentNotAllowedException::class);
});

it('refuses a second shipment for one order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'payment_method' => PaymentMethod::Stripe,
    ]);

    app(CreateShipment::class)->handle($order, activeCarrier(), null);

    expect(fn () => app(CreateShipment::class)->handle($order, activeCarrier(), null))
        ->toThrow(ShipmentNotAllowedException::class);

    expect(Shipment::where('order_id', $order->getKey())->count())->toBe(1);
});

it('refuses an inactive carrier', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'payment_method' => PaymentMethod::Stripe,
    ]);

    $inactive = Carrier::factory()->create(['is_active' => false]);

    expect(fn () => app(CreateShipment::class)->handle($order, $inactive, null))
        ->toThrow(ShipmentNotAllowedException::class);
});

it('denies an actor without create_shipment', function (): void {
    // Granted update_shipment rather than nothing: an actor holding no
    // permission at all is denied by whichever check runs first, which would
    // not prove this gate specifically.
    $actor = User::factory()->create();
    $actor->givePermissionTo('update_shipment');

    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'payment_method' => PaymentMethod::Stripe,
    ]);

    expect(fn () => app(CreateShipment::class)->handle($order, activeCarrier(), $actor))
        ->toThrow(AuthorizationException::class);

    expect(Shipment::where('order_id', $order->getKey())->exists())->toBeFalse();
});

it('allows an actor holding create_shipment', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('create_shipment');

    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'payment_method' => PaymentMethod::Stripe,
    ]);

    $shipment = app(CreateShipment::class)->handle($order, activeCarrier(), $actor);

    expect($shipment->exists)->toBeTrue();
});
