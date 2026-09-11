<?php

declare(strict_types=1);

namespace App\Actions\Shipment;

use App\Exceptions\CarrierCannotBeDeletedException;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes a carrier, refusing while it still has shipments.
 *
 * `shipments.carrier_id`'s foreign key already blocks this at the database
 * as error 1451, a raw `QueryException`. This Action turns that into a
 * message an administrator can act on, thrown from inside a lock rather
 * than left to the database: a shipment created for this carrier in the
 * same instant is either already visible to the count or is itself blocked
 * waiting on the lock — see `explanation/concurrency-and-locking.md`.
 *
 * `orders.carrier_id` is deliberately not checked — it's `nullOnDelete()`,
 * so an order referencing this carrier does not block the delete.
 *
 * Authorizes `delete_carrier`. Locks `carriers`.
 */
final class DeleteCarrier
{
    /**
     * @throws CarrierCannotBeDeletedException
     */
    public function handle(Carrier $carrier, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $carrier);
        }

        DB::transaction(function () use ($carrier): void {
            /** @var Carrier $locked */
            $locked = Carrier::query()->lockForUpdate()->findOrFail($carrier->getKey());

            $shipments = $locked->shipments()->count();

            if ($shipments > 0) {
                throw CarrierCannotBeDeletedException::hasShipments($locked, $shipments);
            }

            $locked->delete();
        });
    }
}
