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
 * There is deliberately no `$orderId` property and no per-order route here.
 * A customer opening one of these rows goes to
 * `checkout.confirmation`, which already performs the ownership check on
 * every render (not only on mount). Adding a second detail page would mean a
 * second entitlement check to keep correct, and ADR-0014's reasoning applies:
 * this page holds no invariant an Action could own, it is a scoped read.
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
        return view('livewire.account.order-history', [
            'orders' => $this->orders(),
        ]);
    }
}
