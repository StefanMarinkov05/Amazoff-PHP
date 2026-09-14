<?php

declare(strict_types=1);

use App\Actions\Shipment\SyncShipmentTracking;
use App\Enums\ShipmentStatus;
use App\Exceptions\CourierUnavailableException;
use App\Exceptions\ShipmentNotTrackableException;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Courier\CourierTrackingEvent;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * The connector-calling half of slice 8: polls CourierGateway::track() and
 * applies whatever it returns through TransitionShipmentStatus, which is
 * the thing that actually writes shipments.status and
 * shipment_tracking_events. This Action writes nothing directly, so most of
 * what it "proves" is the composition — the right events reach
 * TransitionShipmentStatus in the right order, and a stale one does not
 * abort the rest of the batch.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);

    swapFakeCourierManager();
});

function trackableShipmentAt(ShipmentStatus $status, array $overrides = []): Shipment
{
    return Shipment::factory()->create(array_merge([
        'carrier_id' => fakeCourierCarrier()->getKey(),
        'status' => $status,
        'tracking_number' => 'TRACK-'.fake()->unique()->numerify('######'),
        'shipped_at' => null,
        'delivered_at' => null,
        'raw_status' => null,
    ], $overrides));
}

function trackingEvent(ShipmentStatus $status, string $occurredAt, ?string $description = null): CourierTrackingEvent
{
    return new CourierTrackingEvent(
        rawStatus: strtoupper($status->value),
        status: $status,
        occurredAt: new DateTimeImmutable($occurredAt),
        description: $description,
    );
}

it('applies a single tracking event through TransitionShipmentStatus', function (): void {
    $shipment = trackableShipmentAt(ShipmentStatus::Pending);

    fakeCourier()->fakeTrackingEvents = collect([
        trackingEvent(ShipmentStatus::Shipped, '2026-09-14 08:00:00', 'Handed to courier'),
    ]);

    $synced = app(SyncShipmentTracking::class)->handle($shipment, null);

    expect($synced->status)->toBe(ShipmentStatus::Shipped)
        ->and($synced->raw_status)->toBe('SHIPPED')
        ->and($synced->shipped_at)->not->toBeNull()
        ->and($synced->shipmentTrackingEvents()->count())->toBe(1);
});

it('applies several events in chronological order regardless of the order the courier returned them', function (): void {
    $shipment = trackableShipmentAt(ShipmentStatus::Pending);

    fakeCourier()->fakeTrackingEvents = collect([
        trackingEvent(ShipmentStatus::InTransit, '2026-09-14 12:00:00'),
        trackingEvent(ShipmentStatus::Shipped, '2026-09-14 08:00:00'),
    ]);

    $synced = app(SyncShipmentTracking::class)->handle($shipment, null);

    expect($synced->status)->toBe(ShipmentStatus::InTransit)
        ->and($synced->shipmentTrackingEvents()->count())->toBe(2);
});

it('skips a stale or out-of-order event instead of failing the whole sync', function (): void {
    // Cancelled is not in Shipped's allowed transitions ([InTransit,
    // Delivered, Returned]) — a real illegal move, not merely a repeat, so
    // this genuinely exercises IllegalShipmentStatusTransitionException
    // rather than TransitionShipmentStatus's separate same-status no-op.
    // Chronologically: Shipped, then the illegal stale Cancelled, then a
    // legal Delivered — the batch must still apply the last one.
    $shipment = trackableShipmentAt(ShipmentStatus::Pending);

    fakeCourier()->fakeTrackingEvents = collect([
        trackingEvent(ShipmentStatus::Shipped, '2026-09-14 07:00:00'),
        trackingEvent(ShipmentStatus::Cancelled, '2026-09-14 08:00:00'),
        trackingEvent(ShipmentStatus::Delivered, '2026-09-14 09:00:00'),
    ]);

    $synced = app(SyncShipmentTracking::class)->handle($shipment, null);

    expect($synced->status)->toBe(ShipmentStatus::Delivered)
        ->and($synced->shipmentTrackingEvents()->count())->toBe(2);
});

it('refuses a shipment with no tracking number yet', function (): void {
    $shipment = trackableShipmentAt(ShipmentStatus::Pending, ['tracking_number' => null]);

    expect(fn () => app(SyncShipmentTracking::class)->handle($shipment, null))
        ->toThrow(ShipmentNotTrackableException::class);

    expect(fakeCourier()->trackCalls)->toBe(0);
});

it('lets a courier outage propagate rather than swallowing it', function (): void {
    $shipment = trackableShipmentAt(ShipmentStatus::Shipped);

    fakeCourier()->failNextTrackCall = true;

    expect(fn () => app(SyncShipmentTracking::class)->handle($shipment, null))
        ->toThrow(CourierUnavailableException::class);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('lets an actor holding update_shipment sync, and passes them through to TransitionShipmentStatus too', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('update_shipment');

    $shipment = trackableShipmentAt(ShipmentStatus::Pending);

    fakeCourier()->fakeTrackingEvents = collect([
        trackingEvent(ShipmentStatus::Shipped, '2026-09-14 08:00:00'),
    ]);

    $synced = app(SyncShipmentTracking::class)->handle($shipment, $actor);

    expect($synced->status)->toBe(ShipmentStatus::Shipped);
});

it('denies an actor without update_shipment before ever calling the courier', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('create_shipment');

    $shipment = trackableShipmentAt(ShipmentStatus::Pending);

    fakeCourier()->fakeTrackingEvents = collect([
        trackingEvent(ShipmentStatus::Shipped, '2026-09-14 08:00:00'),
    ]);

    expect(fn () => app(SyncShipmentTracking::class)->handle($shipment, $actor))
        ->toThrow(AuthorizationException::class);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Pending)
        ->and(fakeCourier()->trackCalls)->toBe(0);
});

it('is a no-op when the courier returns nothing new', function (): void {
    $shipment = trackableShipmentAt(ShipmentStatus::Shipped, ['shipped_at' => now()->subDay()]);

    fakeCourier()->fakeTrackingEvents = collect();

    $synced = app(SyncShipmentTracking::class)->handle($shipment, null);

    expect($synced->status)->toBe(ShipmentStatus::Shipped)
        ->and($synced->shipmentTrackingEvents()->count())->toBe(0);
});

it('denies an unauthorized actor even when the only event would have been a silent no-op', function (): void {
    // The gap this Action's own authorization check exists to close:
    // TransitionShipmentStatus's same-status no-op returns before its own
    // Gate::authorize() call ever runs, so an actor with zero permissions
    // calling straight through to it — bypassing this Action entirely —
    // would see no exception at all on a repeat event. Confirmed live
    // before this check was added: no exception, a "successful" return.
    $actor = User::factory()->create();

    $shipment = trackableShipmentAt(ShipmentStatus::Shipped, ['shipped_at' => now()]);

    fakeCourier()->fakeTrackingEvents = collect([
        trackingEvent(ShipmentStatus::Shipped, '2026-09-14 08:00:00'),
    ]);

    expect(fn () => app(SyncShipmentTracking::class)->handle($shipment, $actor))
        ->toThrow(AuthorizationException::class);

    expect(fakeCourier()->trackCalls)->toBe(0);
});
