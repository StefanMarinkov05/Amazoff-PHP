<?php

declare(strict_types=1);

use App\Actions\Shipment\SyncShipmentTracking as SyncShipmentTrackingAction;
use App\Enums\ShipmentStatus;
use App\Jobs\SyncShipmentTracking;
use App\Models\Shipment;
use App\Support\Courier\CourierTrackingEvent;

/*
 * The queue-facing wrapper around SyncShipmentTracking (the Action). What
 * this proves is specific to the Job: it resolves the shipment fresh from
 * its serialized id rather than trusting a frozen copy, calls the Action
 * with a null (system) actor, and tolerates the row being gone by the time
 * a worker picks it up.
 */

beforeEach(function (): void {
    swapFakeCourierManager();
});

it('resolves the shipment fresh from its id and calls the Action with a null actor', function (): void {
    $shipment = Shipment::factory()->create([
        'carrier_id' => fakeCourierCarrier()->getKey(),
        'status' => ShipmentStatus::Pending,
        'tracking_number' => 'TRACK-JOB-1',
    ]);

    fakeCourier()->fakeTrackingEvents = collect([
        new CourierTrackingEvent(
            rawStatus: 'SHIPPED',
            status: ShipmentStatus::Shipped,
            occurredAt: new DateTimeImmutable('2026-09-14 08:00:00'),
            description: null,
        ),
    ]);

    (new SyncShipmentTracking($shipment->getKey()))->handle(app(SyncShipmentTrackingAction::class));

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('does nothing and does not throw when the shipment no longer exists', function (): void {
    $missingId = Shipment::factory()->create()->getKey();
    Shipment::whereKey($missingId)->delete();

    (new SyncShipmentTracking($missingId))->handle(app(SyncShipmentTrackingAction::class));

    expect(fakeCourier()->trackCalls)->toBe(0);
});
