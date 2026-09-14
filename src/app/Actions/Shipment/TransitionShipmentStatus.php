<?php

declare(strict_types=1);

namespace App\Actions\Shipment;

use App\Enums\ShipmentStatus;
use App\Exceptions\IllegalShipmentStatusTransitionException;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single writer of `shipments.status`, and the only thing that appends to
 * `shipment_tracking_events`.
 *
 * ADR-0004's shipment equivalent of `TransitionOrderStatus`. Every move
 * writes a tracking event, so the shipment's history is a consequence of
 * transitioning rather than something a caller has to remember — the same
 * reasoning ADR-0011 gives for putting the order's inventory effect inside
 * the transition rather than in a wrapper.
 *
 * ## The self-transition is legal here, and means something
 *
 * `ShipmentStatus` has no self-referencing case, so unlike `PaymentStatus`
 * a repeat is never a real second event — but unlike `OrderStatus`, a
 * courier polling loop will genuinely deliver the same status twice, and
 * often. `SyncShipmentTracking` (slice 8) will call this on every poll.
 *
 * So a repeat is a no-op *for the status column*, and still appends nothing:
 * writing a tracking event per poll would fill the table with duplicates of
 * "still in transit". The event is written only when the status actually
 * moves. A courier's own richer timeline, if one is ever wanted, is a
 * separate ingestion path, not this.
 *
 * `raw_status` is stored as the courier sent it, alongside the mapped enum.
 * Two vocabularies map onto one enum and the mapping will be wrong
 * eventually; keeping the original is what makes that debuggable rather than
 * lost.
 *
 * Locks `shipments`. Does not touch `orders.status` — whether a delivered
 * shipment advances its order is `TransitionOrderStatus`'s decision, with
 * its own policy, and coupling them here would let a courier webhook move an
 * order with nobody authorising it.
 *
 * Authorizes `update_shipment`; `warehouse_employee` holds it.
 * ADR-0004 · ADR-0011 · reference/write-rules/order.md
 */
final class TransitionShipmentStatus
{
    /**
     * @param  string|null  $rawStatus  The courier's own status string, kept
     *                                  verbatim so a bad mapping stays
     *                                  diagnosable.
     *
     * @throws IllegalShipmentStatusTransitionException
     */
    public function handle(
        Shipment $shipment,
        ShipmentStatus $to,
        ?User $actor,
        ?string $rawStatus = null,
        ?string $description = null,
    ): Shipment {
        return DB::transaction(function () use ($shipment, $to, $actor, $rawStatus, $description): Shipment {
            /** @var Shipment $locked */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());
            $from = $locked->status;

            // The no-op comes before the legality check, not after. A poll
            // repeating `Delivered` must not raise — `Delivered` does not
            // list itself, so the matrix would refuse it — and a courier
            // repeating itself is not an error worth surfacing.
            if ($from === $to) {
                return $locked;
            }

            if (! $from->canTransitionTo($to)) {
                throw new IllegalShipmentStatusTransitionException($locked, $from, $to);
            }

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('update', $locked);
            }

            $now = Carbon::now();
            $attributes = ['status' => $to, 'raw_status' => $rawStatus];

            // Stamped on first arrival only. Shipped => InTransit => Delivered
            // passes through each once, and Delivered => Returned must not
            // clear the delivery timestamp — the parcel was delivered, and
            // then came back.
            if ($to === ShipmentStatus::Shipped && $locked->shipped_at === null) {
                $attributes['shipped_at'] = $now;
            }

            if ($to === ShipmentStatus::Delivered && $locked->delivered_at === null) {
                $attributes['delivered_at'] = $now;
            }

            $locked->update($attributes);

            $locked->shipmentTrackingEvents()->create([
                'status' => $to,
                'raw_status' => $rawStatus,
                'description' => $description,
                'event_time' => $now,
            ]);

            return $locked->refresh();
        });
    }
}
