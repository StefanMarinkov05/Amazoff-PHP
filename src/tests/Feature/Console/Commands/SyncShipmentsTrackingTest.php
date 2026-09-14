<?php

declare(strict_types=1);

use App\Enums\ShipmentStatus;
use App\Jobs\SyncShipmentTracking;
use App\Models\Shipment;
use Illuminate\Support\Facades\Queue;

/*
 * shipments:sync-tracking — the scheduled sweep that queues one
 * SyncShipmentTracking Job per shipment still worth polling. The Action's
 * own behaviour is SyncShipmentTrackingTest; what this proves is the
 * command's own filter: which shipments get a Job, and which don't.
 */

it('queues a Job for every shipment still in transit with a tracking number', function (): void {
    Queue::fake();

    $shipped = Shipment::factory()->create(['status' => ShipmentStatus::Shipped, 'tracking_number' => 'TRACK-1']);
    $inTransit = Shipment::factory()->create(['status' => ShipmentStatus::InTransit, 'tracking_number' => 'TRACK-2']);

    $this->artisan('shipments:sync-tracking')
        ->expectsOutputToContain('Queued tracking sync for 2 shipment(s).')
        ->assertSuccessful();

    Queue::assertPushed(SyncShipmentTracking::class, fn (SyncShipmentTracking $job): bool => $job->shipmentId === $shipped->getKey());
    Queue::assertPushed(SyncShipmentTracking::class, fn (SyncShipmentTracking $job): bool => $job->shipmentId === $inTransit->getKey());
    Queue::assertPushed(SyncShipmentTracking::class, 2);
});

it('does not queue a Pending shipment, which has nothing to track yet', function (): void {
    Queue::fake();

    Shipment::factory()->create(['status' => ShipmentStatus::Pending, 'tracking_number' => null]);

    $this->artisan('shipments:sync-tracking')->assertSuccessful();

    Queue::assertNotPushed(SyncShipmentTracking::class);
});

it('does not queue a terminal shipment even if it still carries a tracking number', function (): void {
    Queue::fake();

    Shipment::factory()->create(['status' => ShipmentStatus::Delivered, 'tracking_number' => 'TRACK-3']);
    Shipment::factory()->create(['status' => ShipmentStatus::Returned, 'tracking_number' => 'TRACK-4']);
    Shipment::factory()->create(['status' => ShipmentStatus::Cancelled, 'tracking_number' => 'TRACK-5']);

    $this->artisan('shipments:sync-tracking')->assertSuccessful();

    Queue::assertNotPushed(SyncShipmentTracking::class);
});

it('does not queue a Shipped shipment with no tracking number yet', function (): void {
    Queue::fake();

    Shipment::factory()->create(['status' => ShipmentStatus::Shipped, 'tracking_number' => null]);

    $this->artisan('shipments:sync-tracking')->assertSuccessful();

    Queue::assertNotPushed(SyncShipmentTracking::class);
});

it('reports zero rather than failing when nothing needs syncing', function (): void {
    Queue::fake();

    $this->artisan('shipments:sync-tracking')
        ->expectsOutputToContain('Queued tracking sync for 0 shipment(s).')
        ->assertSuccessful();
});

it('does not queue a second Job for a shipment whose first Job is still in flight', function (): void {
    // The scenario ShouldBeUnique on the Job exists for: this sweep runs
    // every 5 minutes, but a single Job's own retry cycle can span up to
    // 450 seconds — long enough to still be unresolved when the next sweep
    // fires. Queue::fake() does not itself enforce uniqueness, but the lock
    // acquisition happens upstream of it, in PendingDispatch, against the
    // real cache store — so this proves the real mechanism, not a fake one.
    Queue::fake();

    $shipment = Shipment::factory()->create(['status' => ShipmentStatus::Shipped, 'tracking_number' => 'TRACK-DUP']);

    $this->artisan('shipments:sync-tracking')->assertSuccessful();
    // Simulates the next scheduled sweep firing before the first Job for
    // this shipment has been processed or released its lock.
    $this->artisan('shipments:sync-tracking')->assertSuccessful();

    Queue::assertPushed(SyncShipmentTracking::class, fn (SyncShipmentTracking $job): bool => $job->shipmentId === $shipment->getKey());
    Queue::assertPushed(SyncShipmentTracking::class, 1);
});
