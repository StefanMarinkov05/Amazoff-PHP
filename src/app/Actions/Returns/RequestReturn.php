<?php

declare(strict_types=1);

namespace App\Actions\Returns;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\ReturnNotAllowedException;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A customer asks to withdraw from part or all of a delivered order
 * (ADR-0020, CRD Arts. 9–15).
 *
 * Authorizes nothing — like `CreateProductReview`, the customer is the owner
 * of the act and ownership is proven by the order being theirs (the caller
 * scopes `auth()->user()->orders()`), not by a permission. The `?User $actor`
 * stays for ADR-0007's shape.
 *
 * ## The 14-day window is an Action guard, not UI-only
 *
 * `RequestReturn` refuses if the order has not reached `Delivered` or if more
 * than `config('returns.withdrawal_days')` have passed since it did
 * (`Order::deliveredAt()`, read from the status-history row). A form that
 * hides the button is not the enforcement.
 *
 * ## No stock or payment side effect here
 *
 * The item has not physically come back. `RestockReturn` and `RefundPayment`
 * run in `RefundReturn`, once staff have received and approved it.
 *
 * Contested: locks `orders`, because "how much of this line is still
 * returnable" is a check-then-act window two concurrent requests would both
 * pass. `write-rules/returns.md`.
 */
final class RequestReturn
{
    /**
     * @param  array<int, int>  $lines  order_item_id => quantity
     *
     * @throws ReturnNotAllowedException
     */
    public function handle(Order $order, array $lines, string $reason, ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($order, $lines, $reason): OrderReturn {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::Delivered) {
                throw ReturnNotAllowedException::orderNotDelivered($locked);
            }

            $configured = config('returns.withdrawal_days', 14);
            $days = is_numeric($configured) ? (int) $configured : 14;
            $deliveredAt = $locked->deliveredAt();

            if ($deliveredAt === null || $deliveredAt->copy()->addDays($days)->isPast()) {
                throw ReturnNotAllowedException::windowExpired($locked, $days);
            }

            $wanted = array_filter($lines, static fn (int $qty): bool => $qty > 0);

            if ($wanted === []) {
                throw ReturnNotAllowedException::nothingSelected($locked);
            }

            $orderItems = $locked->orderItems()->get()->keyBy('id');
            $alreadyReturned = $this->alreadyReturnedQuantities($locked);

            foreach ($wanted as $orderItemId => $quantity) {
                $item = $orderItems->get($orderItemId);

                if ($item === null) {
                    throw ReturnNotAllowedException::lineNotOnOrder($locked, $orderItemId);
                }

                $remaining = $item->quantity - ($alreadyReturned[$orderItemId] ?? 0);

                if ($quantity > $remaining) {
                    throw ReturnNotAllowedException::quantityExceedsRemaining(
                        $locked,
                        $orderItemId,
                        $quantity,
                        max(0, $remaining),
                    );
                }
            }

            /** @var OrderReturn $return */
            $return = $locked->returns()->create([
                'status' => ReturnStatus::Requested,
                'reason' => (string) str($reason)->sanitizeHtml(),
                'requested_at' => now(),
            ]);

            foreach ($wanted as $orderItemId => $quantity) {
                $return->returnItems()->create([
                    'order_item_id' => $orderItemId,
                    'quantity' => $quantity,
                ]);
            }

            return $return->load('returnItems');
        });
    }

    /**
     * Quantity already spoken for per order line — every return on this order
     * except the denied ones (a denied request releases the quantity it held).
     *
     * @return array<int, int>
     */
    private function alreadyReturnedQuantities(Order $order): array
    {
        $totals = [];

        $returns = $order->returns()
            ->where('status', '!=', ReturnStatus::Denied)
            ->with('returnItems')
            ->get();

        foreach ($returns as $return) {
            foreach ($return->returnItems as $item) {
                $totals[$item->order_item_id] = ($totals[$item->order_item_id] ?? 0) + $item->quantity;
            }
        }

        return $totals;
    }
}
