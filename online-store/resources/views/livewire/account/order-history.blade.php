<div class="mx-auto w-full max-w-4xl px-4 py-12 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Your orders</h1>
    <p class="mt-2 text-sm text-ink-500">
        Every order placed while signed in to this account.
    </p>

    @if ($orders->isEmpty())
        <p class="mt-8 rounded-control border border-ink-200 bg-white px-4 py-6 text-sm text-ink-500">
            You have not placed an order yet.
            <a href="/catalogue" wire:navigate
               class="font-medium text-marine-700 underline-offset-4 hover:underline">
                Browse the catalogue
            </a>
        </p>
    @else
        <ul class="mt-8 divide-y divide-ink-200 rounded-control border border-ink-200 bg-white">
            @foreach ($orders as $order)
                <li class="flex flex-wrap items-center justify-between gap-4 px-4 py-4">
                    <div class="min-w-0">
                        <a
                            href="{{ route('checkout.confirmation', ['order' => $order->id]) }}"
                            wire:navigate
                            class="text-sm font-medium text-marine-700 underline-offset-4 hover:underline
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 rounded-sm"
                        >
                            {{ $order->serial_number }}
                        </a>
                        <p class="mt-1 text-xs text-ink-500">
                            {{ $order->created_at?->format('j M Y') }}
                            · {{ $order->orderItems->count() }}
                            {{ Str::plural('item', $order->orderItems->count()) }}
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

                        <span class="text-sm font-semibold text-ink-900">{{ $order->total_amount }}</span>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            {{ $orders->links() }}
        </div>
    @endif
</div>
