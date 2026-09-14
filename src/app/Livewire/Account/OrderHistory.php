<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A signed-in customer's own orders (§19, §26).
 *
 * ## The scoping is the whole component
 *
 * Every query here starts from `auth()->user()->orders()`, never
 * `Order::query()`. CLAUDE.md states the rule and `OrderConfirmation`'s
 * docblock explains the attack it prevents: `orders.serial_number` is
 * sequential, so any unscoped lookup enumerates every customer's name,
 * address and contents. A policy check on a row already loaded is the weaker
 * half — scoping stops the row being loaded at all.
 *
 * A row links to `account.orders.show` (`OrderDetails`), which re-runs the
 * same `auth()->user()->orders()->findOrFail()` scoping on every render.
 * That entitlement check is one line, the same one this component uses —
 * see `OrderDetails`'s docblock for why the earlier "a second page means a
 * second check to keep correct" objection did not hold up.
 *
 * The route is behind `auth` middleware, so `auth()->user()` cannot be null
 * here. It is still narrowed explicitly with an instanceof check rather than
 * asserted with a docblock annotation, because the middleware is a routing
 * fact and this class should not break silently if it is ever mounted from
 * somewhere else.
 */
#[Layout('components.layouts.app')]
class OrderHistory extends Component
{
    use WithPagination;

    /**
     * Orders belonging to the signed-in customer, newest first.
     *
     * Eager-loads what the list renders. `payment` feeds the status column
     * and `orderItems` the item count — without both, this is one extra
     * query per row, which renders correctly and is invisible until the
     * order count grows. Same N+1 rule CLAUDE.md states for Filament tables.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    private function orders(): LengthAwarePaginator
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            // Unreachable behind `auth`, and an empty paginator rather than a
            // crash if that ever changes.
            return Order::query()->whereRaw('1 = 0')->paginate(10);
        }

        return $user->orders()
            ->with(['payment', 'orderItems'])
            ->latest('id')
            ->paginate(10);
    }

    public function render(): View
    {
        // Split the current page into "in progress" and "concluded" so a
        // customer with a long history sees the orders that still need
        // watching first. Grouped in PHP, not two queries: the pagination
        // is over the whole set (concluded orders are the bulk of an old
        // account's history, and a per-group paginator would need its own
        // page state).
        $orders = $this->orders();

        [$concluded, $active] = collect($orders->items())
            ->partition(fn (Order $order): bool => $order->status->isConcluded());

        return view('livewire.account.order-history', [
            'orders' => $orders,
            'activeOrders' => $active,
            'concludedOrders' => $concluded,
        ]);
    }
}
