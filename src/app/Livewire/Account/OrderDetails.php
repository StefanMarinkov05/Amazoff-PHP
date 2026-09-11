<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Enums\AddressType;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A signed-in customer's single order — contents, addresses, payment, and
 * shipment/tracking state (§19, §26).
 *
 * ## The scoping is the whole component, again
 *
 * `OrderHistory`'s docblock argued against a per-order page on the grounds
 * that it would mean "a second entitlement check to keep correct." That
 * check is one line — `auth()->user()->orders()->findOrFail($id)` — the
 * same scoped lookup `OrderHistory` itself uses, and the same one
 * `ManageAddresses` and `EditProfile` use for their own aggregates. It is
 * re-run on every render via the `order()` method, not cached from mount,
 * so a status change between the click and a re-render is reflected and the
 * entitlement is a property of the id being `#[Locked]` rather than of a
 * model hydrated once. `orders.serial_number` is sequential, so the lookup
 * must start from the user's own relation — never `Order::find()`. SEC-002.
 *
 * `OrderConfirmation` (the post-checkout "thank you" page) stays a separate
 * thing: it is reachable by a guest via a one-time session claim and shows
 * less. This page is for the account holder and shows the address the
 * parcel is going to and where the parcel is.
 */
#[Layout('components.layouts.app')]
class OrderDetails extends Component
{
    /**
     * The order's id, from the route. `#[Locked]` because it feeds a
     * lookup — the standing rule from SEC-001/SEC-002.
     */
    #[Locked]
    public int $orderId;

    public function mount(Order $order): void
    {
        // Route-model binding resolves the row; the ownership check is
        // below in order(). Keep only the id, so every render re-scopes.
        $key = $order->getKey();

        if (! is_int($key)) {
            abort(404);
        }

        $this->orderId = $key;

        // Fail fast on mount too, so a stranger's id 404s immediately
        // rather than rendering an empty shell.
        $this->order();
    }

    /**
     * The order, scoped to the signed-in customer. 404s for anyone else's
     * id. Eager-loads everything the view renders — items with their
     * product (for the link), addresses, payment, shipment with its
     * carrier — one query set, per ADR-0012's per-component eager-load rule.
     */
    public function order(): Order
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $order = $user->orders()
            ->with([
                'orderItems.product:id,slug,name',
                'orderAddresses',
                'payment',
                'shipment.carrier:id,name',
                'returns.returnItems',
            ])
            ->find($this->orderId);

        // 404, not a ModelNotFoundException: this is a scoped read, and an
        // id that is not one of the customer's orders is indistinguishable
        // from an id that does not exist — the same non-disclosure
        // OrderHistory keeps.
        abort_if($order === null, 404);

        return $order;
    }

    public function shippingAddress(): ?OrderAddress
    {
        return $this->order()->orderAddresses
            ->firstWhere('type', AddressType::Delivery);
    }

    public function billingAddress(): ?OrderAddress
    {
        return $this->order()->orderAddresses
            ->firstWhere('type', AddressType::Billing);
    }

    /**
     * Whether a return can still be requested for this order — delivered, and
     * within the 14-day withdrawal window (`RequestReturn`'s own guard, shown
     * here so the button only appears when the click would succeed). The rule
     * lives in `Order::deliveredAt()` and `config('returns.withdrawal_days')`,
     * not restated.
     */
    public function returnWindowOpen(): bool
    {
        $deliveredAt = $this->order()->deliveredAt();

        if ($deliveredAt === null) {
            return false;
        }

        $configured = config('returns.withdrawal_days', 14);
        $days = is_numeric($configured) ? (int) $configured : 14;

        return ! $deliveredAt->copy()->addDays($days)->isPast();
    }

    public function render(): View
    {
        $order = $this->order();

        return view('livewire.account.order-details', [
            'order' => $order,
            'shippingAddress' => $this->shippingAddress(),
            'billingAddress' => $this->billingAddress(),
            'returnWindowOpen' => $this->returnWindowOpen(),
        ]);
    }
}
