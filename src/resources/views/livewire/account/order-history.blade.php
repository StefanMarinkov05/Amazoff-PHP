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
        @if ($activeOrders->isNotEmpty())
            <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-500">In progress</h2>
            <ul class="mt-3 divide-y divide-ink-200 rounded-control border border-ink-200 bg-white">
                @foreach ($activeOrders as $order)
                    <x-account.order-row :$order wire:key="order-{{ $order->id }}" />
                @endforeach
            </ul>
        @endif

        @if ($concludedOrders->isNotEmpty())
            <h2 @class([
                'text-sm font-semibold uppercase tracking-wide text-ink-500',
                'mt-10' => $activeOrders->isNotEmpty(),
                'mt-8' => $activeOrders->isEmpty(),
            ])>Completed</h2>
            <ul class="mt-3 divide-y divide-ink-200 rounded-control border border-ink-200 bg-white">
                @foreach ($concludedOrders as $order)
                    <x-account.order-row :$order wire:key="order-{{ $order->id }}" />
                @endforeach
            </ul>
        @endif

        <div class="mt-6">
            {{ $orders->links() }}
        </div>
    @endif
</div>
