<?php

declare(strict_types=1);

namespace App\Actions\Returns;

use App\Actions\Inventory\RestockReturn;
use App\Actions\Payment\RefundPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\ReturnNotAllowedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\ProductVariation;
use App\Models\ReturnItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Staff refund an approved return: money back, stock back (ADR-0020).
 *
 * Composes rather than reimplements — `RefundPayment` is the one path that
 * moves money and holds the refund cap, `RestockReturn` is the one path that
 * credits returned stock. This Action does not touch `orders.status`: the
 * `OrderReturn` aggregate is independent of it (ADR-0020), so the existing
 * whole-order `OrderStatus::Returned` effect and this granular refund cannot
 * double-count.
 *
 * ## Cash on delivery
 *
 * A COD order has no Stripe charge to reverse (`RefundPayment` refuses one).
 * The return is still marked `Refunded` with a note that the cash refund is
 * handled offline, and the stock is still restocked — staff reconcile the
 * cash separately. Decision locked with the project lead; ADR-0020.
 *
 * ## What is refunded
 *
 * Goods value only: `sum(order_item.unit_price * return_item.quantity)`, gross
 * (prices are stored gross). Delivery-cost reimbursement on a *full*
 * withdrawal (CRD Art. 13) is **not** handled here — a documented counsel gap
 * (`write-rules/returns.md`, `regulatory-compliance.md`).
 *
 * Authorizes `refund` on the return (`refund_return`, administrator — ADR-0011,
 * symmetric to `refund_order`). The actor is then passed through to
 * `RefundPayment`, which authorizes `refund_payment` on the payment: the
 * returns workflow and the actual disbursement are separate abilities, and
 * moving money needs the money one too. Both are administrator-only, so in
 * practice one holder; a dedicated returns role would need both granted.
 *
 * Lock order: `returns` → `payments` (via `RefundPayment`) → `inventories`
 * (via `RestockReturn`, per line sorted by `product_variation_id`).
 * `write-rules/concurrency.md`.
 */
final class RefundReturn
{
    public function __construct(
        private readonly RefundPayment $refundPayment,
        private readonly RestockReturn $restockReturn,
    ) {}

    /**
     * @throws ReturnNotAllowedException
     */
    public function handle(OrderReturn $return, ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $actor): OrderReturn {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->getKey());

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('refund', $locked);
            }

            if ($locked->status !== ReturnStatus::Approved) {
                throw ReturnNotAllowedException::notApproved($locked);
            }

            $locked->load([
                'order.payment',
                'returnItems.orderItem.productVariation' => fn ($query) => $query->withTrashed(),
            ]);

            /** @var Order $order */
            $order = $locked->order;

            $goodsTotal = $this->goodsTotal($locked);
            $note = $this->refundMoney($order, $goodsTotal, $actor);
            $this->restock($locked, $actor);

            $locked->update([
                'status' => ReturnStatus::Refunded,
                'refunded_amount' => (string) $goodsTotal,
                'resolution_note' => trim(($locked->resolution_note ?? '')."\n".$note),
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    private function goodsTotal(OrderReturn $return): Money
    {
        $total = Money::zero();

        /** @var ReturnItem $item */
        foreach ($return->returnItems as $item) {
            /** @var OrderItem $orderItem */
            $orderItem = $item->orderItem;

            $total = $total->add(Money::of((string) $orderItem->unit_price)->multiply($item->quantity));
        }

        return $total;
    }

    /**
     * Sends the money back through `RefundPayment` when there is a Stripe
     * charge to reverse; otherwise records that the cash refund is offline.
     * Returns the note to append to the return's resolution note.
     */
    private function refundMoney(Order $order, Money $goodsTotal, ?User $actor): string
    {
        /** @var Payment|null $payment */
        $payment = $order->payment;

        $refundable = $payment !== null
            && $payment->method === PaymentMethod::Stripe
            && $payment->stripe_payment_intent_id !== null
            && in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);

        if (! $refundable) {
            return sprintf(
                'Refund of %s to be handled offline — order %s has no reversible card payment.',
                $goodsTotal,
                $order->serial_number,
            );
        }

        $this->refundPayment->handle($payment, (string) $goodsTotal, $actor);

        return sprintf('Card refund of %s issued via Stripe.', $goodsTotal);
    }

    private function restock(OrderReturn $return, ?User $actor): void
    {
        $items = $return->returnItems
            ->sortBy(function (ReturnItem $item): int {
                /** @var OrderItem $orderItem */
                $orderItem = $item->orderItem;

                return (int) $orderItem->product_variation_id;
            })
            ->values();

        /** @var ReturnItem $item */
        foreach ($items as $item) {
            /** @var OrderItem $orderItem */
            $orderItem = $item->orderItem;
            $variation = $orderItem->productVariation;

            if (! $variation instanceof ProductVariation) {
                throw new RuntimeException("Return item {$item->id} has no product variation to restock.");
            }

            $this->restockReturn->handle(
                $variation,
                $item->quantity,
                $actor,
                "Return #{$return->id}",
            );
        }
    }
}
