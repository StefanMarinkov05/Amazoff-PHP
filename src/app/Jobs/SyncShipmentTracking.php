<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Shipment\SyncShipmentTracking as SyncShipmentTrackingAction;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs `SyncShipmentTracking` off the request/scheduler cycle — dispatched
 * once per shipment by `shipments:sync-tracking`
 * (`app/Console/Commands/SyncShipmentsTracking.php`), one Job per row rather
 * than one Job looping over all of them, so a courier outage on one shipment
 * cannot delay or fail the sync of every other one in the same sweep.
 *
 * Serializes the shipment **id**, not the model — `explanation/queues-and-jobs.md`'s
 * own stated rule. A Job run minutes after being queued must see the row's
 * current state, not a frozen copy taken at dispatch time.
 *
 * `$tries = 4` deliberately does not match the worker's own `--tries=3` —
 * the worker's flag is the default for a Job that declares nothing of its
 * own, and `attempts() >= $tries` is checked *before* backoff is consulted
 * (`Illuminate\Queue\Worker::markJobAsFailedIfWillExceedMaxAttempts()`), so
 * with 3 tries only `backoff()`'s first two values ever run — the third
 * attempt's failure fails the job outright, before a third delay would
 * apply. 4 tries is what actually spends all three widening delays
 * (30s, 2min, 5min — 450 seconds of patience with a courier having a slow
 * day) rather than leaving the longest one dead. `CourierUnavailableException`
 * is what triggers a retry — the Action itself never catches it, by
 * design, so it reaches the queue's own retry mechanism here.
 *
 * `ShouldBeUnique`, keyed by `shipmentId`: the schedule fires every 5
 * minutes (`shipments:sync-tracking`), but a single Job's own retry cycle
 * can span up to 450 seconds before it either succeeds or exhausts
 * `$tries` — long enough to still be mid-retry when the next sweep
 * dispatches another Job for the same shipment. Without this, a courier
 * having a slow day gets polled by an ever-growing pile of jobs for the
 * same row rather than one job retrying in place, each one a real,
 * possibly rate-limited, courier API call.
 */
final class SyncShipmentTracking implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public readonly int $shipmentId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * The uniqueness key `ShouldBeUnique` locks on — one shipment, one
     * in-flight job at a time. The lock is held (via the `database` cache
     * store's `cache_locks` table, confirmed to support atomic locks) from
     * dispatch until this Job finishes or permanently fails, which is what
     * actually prevents the pile-up `ShouldBeUnique`'s own docblock above
     * describes, not just a snapshot check at dispatch time.
     */
    public function uniqueId(): string
    {
        return (string) $this->shipmentId;
    }

    public function handle(SyncShipmentTrackingAction $action): void
    {
        $shipment = Shipment::find($this->shipmentId);

        if ($shipment === null) {
            // Deleted, or the order/shipment was otherwise removed, between
            // this Job being queued and a worker picking it up. Nothing to
            // sync — not a failure, so no exception and no retry.
            return;
        }

        $action->handle($shipment, null);
    }
}
