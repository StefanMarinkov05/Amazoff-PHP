<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Actions\Returns\RequestReturn as RequestReturnAction;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\ReturnNotAllowedException;
use App\Models\Order;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * A signed-in customer requests a return / withdrawal for one delivered order
 * (ADR-0020, CRD Arts. 9–15).
 *
 * ## The scoping is the whole component, again
 *
 * Exactly the `OrderDetails` pattern: keep only `#[Locked] $orderId`, and
 * re-resolve through `auth()->user()->orders()` on every render, so a stranger's
 * id 404s and a status change between the click and the submit is reflected.
 * `orders.serial_number` is sequential — never `Order::find()`. SEC-002.
 *
 * The 14-day window and the per-line remaining quantity are shown here for the
 * customer, but the enforcement is `RequestReturn`'s Action guard — this form
 * only decides what to render.
 */
#[Layout('components.layouts.app')]
class RequestReturn extends Component
{
    #[Locked]
    public int $orderId;

    /**
     * order_item_id => quantity the customer wants to return.
     *
     * `array<int, int>` describes the *intended* shape, not what Livewire
     * actually hydrates onto it — `wire:model` sends whatever the client
     * sends, so a value can arrive as a nested array or any other shape
     * `submit()` must check for itself, not trust from this annotation
     * (SEC-016).
     *
     * @var array<int, mixed>
     */
    public array $quantities = [];

    #[Validate('required|string|min:3|max:2000')]
    public string $reason = '';

    public ?string $submitted = null;

    public function mount(Order $order): void
    {
        $key = $order->getKey();

        if (! is_int($key)) {
            abort(404);
        }

        $this->orderId = $key;
        $this->order();
    }

    public function order(): Order
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $order = $user->orders()
            ->with(['orderItems', 'returns.returnItems'])
            ->find($this->orderId);

        abort_if($order === null, 404);

        return $order;
    }

    /**
     * Per order line, how many units can still be returned — ordered quantity
     * minus everything held by a return that is not denied.
     *
     * @return array<int, int>
     */
    public function returnableQuantities(): array
    {
        $order = $this->order();

        $held = [];

        foreach ($order->returns as $return) {
            if ($return->status === ReturnStatus::Denied) {
                continue;
            }

            foreach ($return->returnItems as $item) {
                $held[$item->order_item_id] = ($held[$item->order_item_id] ?? 0) + $item->quantity;
            }
        }

        $returnable = [];

        foreach ($order->orderItems as $item) {
            $returnable[$item->id] = max(0, $item->quantity - ($held[$item->id] ?? 0));
        }

        return $returnable;
    }

    public function windowOpen(): bool
    {
        $order = $this->order();

        if ($order->status !== OrderStatus::Delivered) {
            return false;
        }

        $deliveredAt = $order->deliveredAt();

        return $deliveredAt !== null
            && ! $deliveredAt->copy()->addDays($this->withdrawalDays())->isPast();
    }

    public function submit(): void
    {
        $this->validate();

        /** @var array<int, int> $lines */
        $lines = [];

        // SEC-016. $quantities is array<int, int> only by PHPDoc — Livewire
        // hydrates whatever the client sends, and (int) of any non-empty
        // array is always 1 in PHP regardless of its contents. A blind
        // (int) cast here silently turned a garbage-shaped value into a
        // legitimate-looking "return 1" request instead of refusing it.
        // is_numeric() rejects anything that isn't actually a number before
        // the cast ever runs.
        foreach ($this->quantities as $orderItemId => $quantity) {
            if (! is_numeric($quantity)) {
                $this->addError('quantities', 'One of the quantities entered is not a valid number.');

                return;
            }

            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $lines[(int) $orderItemId] = $quantity;
            }
        }

        try {
            $return = app(RequestReturnAction::class)->handle(
                $this->order(),
                $lines,
                $this->reason,
                auth()->user(),
            );
        } catch (ReturnNotAllowedException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->submitted = "Return request #{$return->id} received. We will email you once it is reviewed.";
        $this->reset('quantities', 'reason');
    }

    private function withdrawalDays(): int
    {
        $configured = config('returns.withdrawal_days', 14);

        return is_numeric($configured) ? (int) $configured : 14;
    }

    public function render(): View
    {
        $order = $this->order();

        return view('livewire.account.request-return', [
            'order' => $order,
            'windowOpen' => $this->windowOpen(),
            'returnable' => $this->returnableQuantities(),
            'withdrawalDays' => $this->withdrawalDays(),
        ]);
    }
}
