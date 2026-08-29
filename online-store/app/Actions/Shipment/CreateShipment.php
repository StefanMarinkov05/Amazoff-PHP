<?php

declare(strict_types=1);

namespace App\Actions\Shipment;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShipmentNotAllowedException;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opens the shipment row an order travels on, enforcing §28's rule that a
 * shipment cannot be created for an invalid order.
 *
 * The domain half of slice 8, separated from the courier half the same way
 * `RecordPayment` separates from Stripe: `tracking_number`, `label_path`,
 * `courier_tracking_url` and `shipment_number` are all nullable, so a
 * shipment exists and moves through its states before any `CourierGateway`
 * is built. The connector will later fill those columns on a row this Action
 * opened.
 *
 * ## What "invalid order" means, concretely
 *
 * Three refusals, and the third is the one that is easy to get wrong:
 *
 * - **Cancelled.** Never shippable, no path back.
 * - **Already shipped.** `Order::shipment()` is a `HasOne`; a second
 *   shipment for one order is a bug, not a split delivery. If split
 *   deliveries are ever wanted, that is a schema change and an ADR, not an
 *   extra row here.
 * - **Unpaid — unless it is cash on delivery.** A COD order ships *before*
 *   it is paid; that is the whole point of the method. Deciding this on
 *   payment status alone would refuse every legitimate COD shipment, so the
 *   check reads `orders.payment_method` first. CLAUDE.md's COD scope note is
 *   the source of this rule.
 *
 * `cod_amount` is carried onto the shipment for the courier to collect, and
 * is null for a prepaid order — the courier collecting money for an order
 * that already paid is the failure this nullability prevents.
 *
 * Contested: locks `orders`, because "does this order already have a
 * shipment" is a check-then-act window two staff clicking at once would both
 * pass. `shipments.tracking_number` is separately unique, which backstops
 * the courier half later.
 *
 * Authorizes `create_shipment` via `ShipmentPolicy` — §37 criterion 15, and
 * `warehouse_employee` holds it.
 * ADR-0007 · reference/write-rules/order.md
 */
final class CreateShipment
{
    /**
     * @throws ShipmentNotAllowedException
     */
    public function handle(Order $order, Carrier $carrier, ?User $actor): Shipment
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', Shipment::class);
        }

        return DB::transaction(function () use ($order, $carrier): Shipment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $this->assertShippable($locked, $carrier);

            /** @var Shipment $shipment */
            $shipment = $locked->shipment()->create([
                'carrier_id' => $carrier->getKey(),
                'status' => ShipmentStatus::Pending,
                // Only a COD order gives the courier money to collect. A
                // prepaid order carrying a cod_amount would have the courier
                // charge the customer twice.
                'cod_amount' => $locked->payment_method === PaymentMethod::CashOnDelivery
                    ? $locked->total_amount
                    : null,
            ]);

            return $shipment;
        });
    }

    /**
     * @throws ShipmentNotAllowedException
     */
    private function assertShippable(Order $order, Carrier $carrier): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw ShipmentNotAllowedException::orderIsCancelled($order);
        }

        if ($order->shipment()->exists()) {
            throw ShipmentNotAllowedException::alreadyShipped($order);
        }

        if (! $carrier->is_active) {
            throw ShipmentNotAllowedException::carrierIsInactive($order, $carrier);
        }

        // COD ships unpaid by design; everything else must have reached a
        // status that implies the money arrived. Checked against the order's
        // own status rather than the payment row, because an order can be
        // paid without a payment row existing yet in a seeded or COD flow.
        if ($order->payment_method === PaymentMethod::CashOnDelivery) {
            return;
        }

        $paidStatuses = [
            OrderStatus::Paid,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::ReadyForShipment,
            OrderStatus::Shipped,
            OrderStatus::Delivered,
        ];

        if (! in_array($order->status, $paidStatuses, true)) {
            throw ShipmentNotAllowedException::orderIsUnpaid($order);
        }
    }
}
