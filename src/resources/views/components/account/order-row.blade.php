{{-- One row in the account order list. Shared between the "In progress"
     and "Completed" groups on OrderHistory, so a change to how an order
     row reads lands in both. The whole row is the link — to
     account.orders.show (OrderDetails), not checkout.confirmation, which
     is the post-checkout "thank you" page and shows less. --}}
@props(['order'])

<li>
    <a
        href="{{ route('account.orders.show', ['order' => $order->id]) }}"
        wire:navigate
        class="flex flex-wrap items-center justify-between gap-4 px-4 py-4
               hover:bg-ink-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
    >
        <div class="min-w-0">
            <span class="text-sm font-medium text-marine-700">{{ $order->serial_number }}</span>
            <p class="mt-1 text-xs text-ink-500">
                {{ $order->created_at?->format('j M Y') }}
                · {{ $order->orderItems->count() }}
                {{ \Illuminate\Support\Str::plural('item', $order->orderItems->count()) }}
            </p>
        </div>

        <div class="flex items-center gap-4">
            <span class="rounded-full border border-ink-300 px-2.5 py-1 text-xs font-medium text-ink-700">
                {{ $order->status->getLabel() }}
            </span>

            @if ($order->payment !== null)
                <span class="hidden text-xs text-ink-500 sm:inline">
                    {{ $order->payment->status->getLabel() }}
                </span>
            @endif

            <span class="text-sm font-semibold text-ink-900">
                <x-money :amount="$order->total_amount" :currency="$order->currency" />
            </span>
        </div>
    </a>
</li>
