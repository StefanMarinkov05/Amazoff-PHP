<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Jobs\SyncShipmentTracking;
use App\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The scheduled half of slice 8's tracking sync — queries every shipment
 * still worth polling and dispatches one `SyncShipmentTracking` Job per row,
 * rather than syncing inline: a courier call is slow enough, and this sweep
 * runs frequently enough, that doing it inline would risk one run still
 * executing when the next is due.
 *
 * "Still worth polling" is `Shipped` or `InTransit` with a tracking number —
 * `Pending` has nothing to track yet (`DispatchShipment`'s job, not yet
 * built, fills `tracking_number` in), and `Delivered`/`Returned`/`Cancelled`
 * are terminal per `ShipmentStatus::allowedTransitions()`, so nothing further
 * would ever be applied even if polled.
 */
final class SyncShipmentsTracking extends Command
{
    protected $signature = 'shipments:sync-tracking';

    protected $description = 'Queue a tracking sync for every shipment still in transit';

    public function handle(): int
    {
        /** @var Collection<int, int> $ids */
        $ids = Shipment::query()
            ->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::InTransit])
            ->whereNotNull('tracking_number')
            ->pluck('id');

        $ids->each(function (int $id): void {
            SyncShipmentTracking::dispatch($id);
        });

        $this->info("Queued tracking sync for {$ids->count()} shipment(s).");

        return self::SUCCESS;
    }
}
