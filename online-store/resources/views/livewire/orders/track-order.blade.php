<div class="mx-auto w-full max-w-2xl px-4 py-12 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Track an order</h1>
    <p class="mt-2 text-sm text-ink-500">
        Enter your order number and the email address you used at checkout.
    </p>

    <form wire:submit="track" class="mt-8 rounded-control border border-ink-200 bg-white p-5" novalidate>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="serial_number" class="block text-sm font-medium text-ink-800">Order number</label>
                <input
                    id="serial_number"
                    type="text"
                    wire:model="serial_number"
                    autocomplete="off"
                    placeholder="ORD-000001"
                    class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                           text-sm text-ink-900 placeholder:text-ink-400
                           focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"
                >
            </div>

            <div>
                <label for="track_email" class="block text-sm font-medium text-ink-800">Email address</label>
                <input
                    id="track_email"
                    type="email"
                    wire:model="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                           text-sm text-ink-900 placeholder:text-ink-400
                           focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"
                >
            </div>
        </div>

        {{-- One message for every failure mode. Naming which half was wrong
             would confirm a serial number exists — see the component's own
             docblock. --}}
        @error('serial_number')
            <p role="alert" class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @error('email')
            <p role="alert" class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <button
            type="submit"
            class="mt-5 w-full rounded-control bg-marine-700 px-4 py-2.5 text-sm font-medium text-white
                   hover:bg-marine-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                   disabled:cursor-not-allowed disabled:opacity-60"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="track">Track order</span>
            <span wire:loading wire:target="track">Checking…</span>
        </button>
    </form>

    @if ($order !== null)
        {{-- Status, dates and a total only. No address, phone, name, or line
             items: email possession is a weaker claim than a signed-in
             session, so it unlocks correspondingly less. --}}
        <section class="mt-8 rounded-control border border-ink-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">
                Order {{ $order->serial_number }}
            </h2>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-500">Status</dt>
                    <dd class="font-medium text-ink-800">{{ $order->status->getLabel() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-500">Placed</dt>
                    <dd class="font-medium text-ink-800">{{ $order->created_at?->format('j M Y') }}</dd>
                </div>

                @if ($order->shipment !== null)
                    <div class="flex justify-between">
                        <dt class="text-ink-500">Shipment</dt>
                        <dd class="font-medium text-ink-800">{{ $order->shipment->status->getLabel() }}</dd>
                    </div>

                    @if ($order->shipment->tracking_number !== null)
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Tracking number</dt>
                            <dd class="font-medium text-ink-800">{{ $order->shipment->tracking_number }}</dd>
                        </div>
                    @endif

                    @if ($order->shipment->shipped_at !== null)
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Shipped</dt>
                            <dd class="font-medium text-ink-800">
                                {{ $order->shipment->shipped_at->format('j M Y') }}
                            </dd>
                        </div>
                    @endif
                @endif

                <div class="flex justify-between border-t border-ink-200 pt-3 text-base font-semibold">
                    <dt class="text-ink-900">Total</dt>
                    <dd class="text-ink-900">{{ $order->total_amount }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-ink-500">
                Need the full order details? They are in
                <a href="/account/orders" wire:navigate
                   class="font-medium text-marine-700 underline-offset-4 hover:underline">your account</a>
                if you were signed in when you ordered.
            </p>
        </section>
    @endif
</div>
