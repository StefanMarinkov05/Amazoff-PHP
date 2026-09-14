<?php

declare(strict_types=1);

namespace App\Actions\Shipment;

use App\Exceptions\IllegalShipmentStatusTransitionException;
use App\Exceptions\ShipmentNotTrackableException;
use App\Models\Carrier;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Courier\CourierManager;
use Illuminate\Support\Facades\Gate;

/**
 * Polls the courier for a shipment's current tracking state and applies
 * whatever has changed since the last sync — the connector-calling half of
 * slice 8 that `TransitionShipmentStatus`'s own docblock names in advance.
 *
 * Writes nothing directly. Every event `CourierGateway::track()` returns is
 * handed to `TransitionShipmentStatus`, in the order the courier reports it,
 * which is the single writer of `shipments.status` and
 * `shipment_tracking_events` and already owns the transaction, the
 * `lockForUpdate()`, and the same-status no-op that a polling loop needs.
 * This Action adds no lock of its own — it composes into that one via
 * savepoint nesting, per `add-an-action.md`.
 *
 * ## Why a stale or out-of-order event is swallowed, not fatal
 *
 * A single poll can return several events since the last one — Shipped then
 * InTransit, say — and `ShipmentStatus`'s matrix enforces a legal sequence.
 * A courier re-sending an event the shipment has already moved past (a
 * retried webhook payload, an out-of-order response) throws
 * `IllegalShipmentStatusTransitionException`, whose own docblock states this
 * is expected for "a *known* status arriving out of order" — not a defect. One
 * bad event must not block every later, legitimate one in the same batch, so
 * the loop below catches it per event and continues.
 *
 * `CourierUnavailableException` (a real API failure) is deliberately not
 * caught here — it propagates to the caller, which for the scheduled path is
 * `App\Jobs\SyncShipmentTracking`'s own retry/backoff, and for the panel
 * button is `ReportsDomainFailures`, which turns it into a notification.
 *
 * ## Why this checks authorization itself, rather than only relying on
 * `TransitionShipmentStatus`'s own check
 *
 * `TransitionShipmentStatus` authorizes `update_shipment` per event, but
 * only on the branch that actually writes — its same-status no-op returns
 * before the `Gate::authorize()` call runs, and a stale event's
 * `IllegalShipmentStatusTransitionException` is thrown before it too.
 * Confirmed live: an actor holding *no* permissions at all, calling this
 * Action on a shipment whose only new event repeats its current status,
 * gets no exception whatsoever — the call reads as a silent success.
 * Today's one caller with a real actor (`ViewShipment`'s panel button)
 * happens to gate this at the UI layer first
 * (`->authorize('update')` on the Filament action), but this Action cannot
 * assume every future caller will. `add-an-action.md` §4's rule —
 * authorize before doing any work, non-null actor, no default — is checked
 * here explicitly instead, once, regardless of what the courier returns.
 */
final class SyncShipmentTracking
{
    public function __construct(private readonly CourierManager $courier) {}

    /**
     * @throws ShipmentNotTrackableException
     */
    public function handle(Shipment $shipment, ?User $actor): Shipment
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $shipment);
        }

        if ($shipment->tracking_number === null) {
            throw new ShipmentNotTrackableException($shipment);
        }

        /** @var Carrier $carrier */
        $carrier = $shipment->carrier;

        $events = $this->courier->for($carrier)->track($shipment->tracking_number);

        foreach ($events->sortBy('occurredAt') as $event) {
            try {
                app(TransitionShipmentStatus::class)->handle(
                    $shipment,
                    $event->status,
                    $actor,
                    $event->rawStatus,
                    $event->description,
                );
            } catch (IllegalShipmentStatusTransitionException) {
                continue;
            }
        }

        return $shipment->fresh() ?? $shipment;
    }
}
