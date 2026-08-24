<?php

declare(strict_types=1);

use App\Actions\Shipment\TransitionShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\IllegalShipmentStatusTransitionException;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * The single writer of `shipments.status` and the only thing that appends to
 * `shipment_tracking_events`.
 *
 * Two behaviours here are the Action's own and neither is obvious:
 *
 * - A repeat of the current status is a silent no-op that appends NO event.
 *   A courier polling loop delivers the same status constantly, and the
 *   matrix would refuse it (Delivered does not list itself), so the no-op
 *   sits BEFORE the legality check rather than after.
 * - Delivered => Returned must not clear delivered_at. The parcel was
 *   delivered, and then came back; both facts are true.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

function shipmentAt(ShipmentStatus $status, array $overrides = []): Shipment
{
    return Shipment::factory()->create(array_merge([
        'status' => $status,
        'shipped_at' => null,
        'delivered_at' => null,
        'raw_status' => null,
    ], $overrides));
}

it('stamps shipped_at and writes exactly one tracking event', function (): void {
    $shipment = shipmentAt(ShipmentStatus::Pending);

    $moved = app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Shipped, null);

    expect($moved->status)->toBe(ShipmentStatus::Shipped)
        ->and($moved->shipped_at)->not->toBeNull()
        ->and($moved->shipmentTrackingEvents()->count())->toBe(1);
});

it('treats a repeat of the current status as a no-op and appends no second event', function (): void {
    // The polling case. Delivered does not list itself in the matrix, so
    // without the no-op sitting first this would raise rather than return
    // quietly - and each poll would add a duplicate "still delivered" row.
    $shipment = shipmentAt(ShipmentStatus::Pending);

    app(TransitionShipmentStatus::class)->handle($shipment, ShipmentStatus::Shipped, null);

    $repeated = app(TransitionShipmentStatus::class)
        ->handle($shipment->refresh(), ShipmentStatus::Shipped, null);

    expect($repeated->status)->toBe(ShipmentStatus::Shipped)
        ->and($repeated->shipmentTrackingEvents()->count())->toBe(1);
});

it('does not raise when a delivered shipment is polled again', function (): void {
    // Delivered's allowed transitions are [Returned] only, so a repeat is
    // exactly the move the matrix forbids - and exactly what a courier sends.
    $shipment = shipmentAt(ShipmentStatus::Delivered, ['delivered_at' => now()]);

    $repeated = app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Delivered, null);

    expect($repeated->status)->toBe(ShipmentStatus::Delivered)
        ->and($repeated->shipmentTrackingEvents()->count())->toBe(0);
});

it('keeps delivered_at when a delivered parcel is returned', function (): void {
    $deliveredAt = now()->subDays(3);
    $shipment = shipmentAt(ShipmentStatus::Delivered, ['delivered_at' => $deliveredAt]);

    $returned = app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Returned, null);

    expect($returned->status)->toBe(ShipmentStatus::Returned)
        ->and($returned->delivered_at)->not->toBeNull()
        ->and($returned->delivered_at->toDateString())->toBe($deliveredAt->toDateString());
});

it('does not restamp shipped_at on a later move', function (): void {
    $shipment = shipmentAt(ShipmentStatus::Pending);

    $shipped = app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Shipped, null);
    $firstStamp = $shipped->shipped_at;

    $inTransit = app(TransitionShipmentStatus::class)
        ->handle($shipped, ShipmentStatus::InTransit, null);

    expect($inTransit->shipped_at->eq($firstStamp))->toBeTrue();
});

it('stores the courier raw status verbatim on both the shipment and the event', function (): void {
    // Two courier vocabularies map onto one enum and the mapping will be
    // wrong eventually; the original is what makes that debuggable.
    $shipment = shipmentAt(ShipmentStatus::Pending);

    $moved = app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Shipped, null, 'PREDADENA_NA_KURIER', 'Handed to courier');

    expect($moved->raw_status)->toBe('PREDADENA_NA_KURIER');

    $event = $moved->shipmentTrackingEvents()->sole();

    expect($event->raw_status)->toBe('PREDADENA_NA_KURIER')
        ->and($event->description)->toBe('Handed to courier')
        ->and($event->status)->toBe(ShipmentStatus::Shipped);
});

it('refuses a move the matrix does not allow', function (): void {
    // Pending lists only [Shipped, Cancelled]; Delivered is not reachable
    // without shipping first.
    $shipment = shipmentAt(ShipmentStatus::Pending);

    expect(fn () => app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Delivered, null))
        ->toThrow(IllegalShipmentStatusTransitionException::class);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Pending)
        ->and($shipment->shipmentTrackingEvents()->count())->toBe(0);
});

it('denies an actor without update_shipment', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('create_shipment');

    $shipment = shipmentAt(ShipmentStatus::Pending);

    expect(fn () => app(TransitionShipmentStatus::class)
        ->handle($shipment, ShipmentStatus::Shipped, $actor))
        ->toThrow(AuthorizationException::class);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Pending)
        ->and($shipment->shipmentTrackingEvents()->count())->toBe(0);
});
