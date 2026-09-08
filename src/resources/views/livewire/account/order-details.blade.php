<div class="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6">

    <a href="{{ route('account.orders') }}" wire:navigate
       class="text-sm font-medium text-marine-700 underline-offset-4 hover:underline
              focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 rounded-sm">
        ← All orders
    </a>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-ink-900">
            Order {{ $order->serial_number }}
        </h1>
        <span class="rounded-full border border-ink-300 px-2.5 py-1 text-xs font-medium text-ink-700">
            {{ $order->status->getLabel() }}
        </span>
    </div>
    <p class="mt-2 text-sm text-ink-500">
        Placed {{ $order->created_at?->format('j M Y') }}
    </p>

    {{-- ── Contents ─────────────────────────────────────────────────── --}}
    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-500">Items</h2>
    <ul class="mt-4 divide-y divide-ink-200 rounded-control border border-ink-200 bg-white">
        @foreach ($order->orderItems as $item)
            <li wire:key="item-{{ $item->id }}" class="flex items-baseline justify-between gap-4 px-4 py-3 text-sm">
                <span class="min-w-0 text-ink-800">
                    {{-- product_name is the snapshot at order time; the link
                         goes to the live product if it still exists. --}}
                    @if ($item->product !== null)
                        <a href="{{ url('/products/'.$item->product->slug) }}" wire:navigate
                           class="font-medium text-marine-700 underline-offset-4 hover:underline
                                  focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 rounded-sm">
                            {{ $item->product_name }}
                        </a>
                    @else
                        <span class="font-medium text-ink-800">{{ $item->product_name }}</span>
                    @endif
                    <span class="text-ink-400">× {{ $item->quantity }}</span>
                </span>
                <span class="shrink-0 text-ink-700">
                    <x-money :amount="$item->line_total" :currency="$order->currency" />
                </span>
            </li>
        @endforeach
    </ul>

    {{-- ── Totals ──────────────────────────────────────────────────── --}}
    <dl class="mt-4 space-y-2 rounded-control border border-ink-200 bg-white p-4 text-sm">
        <div class="flex justify-between">
            <dt class="text-ink-500">Subtotal</dt>
            <dd class="text-ink-700"><x-money :amount="$order->subtotal_amount" :currency="$order->currency" /></dd>
        </div>
        @if ((float) $order->discount_amount > 0)
            <div class="flex justify-between">
                <dt class="text-ink-500">Discount</dt>
                <dd class="text-ink-700">−<x-money :amount="$order->discount_amount" :currency="$order->currency" /></dd>
            </div>
        @endif
        <div class="flex justify-between">
            <dt class="text-ink-500">Delivery</dt>
            <dd class="text-ink-700"><x-money :amount="$order->shipping_amount" :currency="$order->currency" /></dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-ink-500">VAT (included)</dt>
            <dd class="text-ink-700"><x-money :amount="$order->vat_amount" :currency="$order->currency" /></dd>
        </div>
        <div class="flex justify-between border-t border-ink-200 pt-2 text-base font-semibold">
            <dt class="text-ink-900">Total</dt>
            <dd class="text-ink-900"><x-money :amount="$order->total_amount" :currency="$order->currency" /></dd>
        </div>
    </dl>

    {{-- ── Delivery & tracking ─────────────────────────────────────── --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <section class="rounded-control border border-ink-200 bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Delivery</h2>
            @if ($shippingAddress !== null)
                <address class="mt-3 text-sm not-italic text-ink-700">
                    {{ $shippingAddress->first_name }} {{ $shippingAddress->last_name }}<br>
                    @if ($shippingAddress->delivery_type === \App\Enums\DeliveryType::Office && $shippingAddress->courier_office_name !== null)
                        {{ $shippingAddress->courier_office_name }} (courier office)<br>
                    @elseif ($shippingAddress->street !== null)
                        {{ $shippingAddress->street }}<br>
                    @endif
                    {{ $shippingAddress->postcode }} {{ $shippingAddress->city }}<br>
                    {{ $shippingAddress->country }}<br>
                    <span class="text-ink-500">{{ $shippingAddress->phone }}</span>
                </address>
            @else
                <p class="mt-3 text-sm text-ink-500">No delivery address recorded.</p>
            @endif
        </section>

        <section class="rounded-control border border-ink-200 bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Shipment</h2>
            @if ($order->shipment !== null)
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">Carrier</dt>
                        <dd class="text-ink-700">{{ $order->shipment->carrier?->name ?? $order->carrier?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">Status</dt>
                        <dd class="text-ink-700">{{ $order->shipment->status->getLabel() }}</dd>
                    </div>
                    @if ($order->shipment->tracking_number !== null)
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Tracking</dt>
                            <dd class="font-medium text-ink-800">{{ $order->shipment->tracking_number }}</dd>
                        </div>
                    @else
                        <p class="text-ink-500">
                            A tracking number appears here once the courier has collected the parcel.
                        </p>
                    @endif
                </dl>

                <a href="{{ route('orders.track', ['order' => $order->serial_number]) }}" wire:navigate
                   class="mt-3 inline-block text-sm font-medium text-marine-700 underline-offset-4 hover:underline
                          focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 rounded-sm">
                    Open the tracking page →
                </a>
            @else
                <p class="mt-3 text-sm text-ink-500">
                    Not shipped yet. This updates once the order is dispatched.
                </p>
            @endif
        </section>
    </div>

    {{-- ── Billing (only if it differs) ────────────────────────────── --}}
    @if ($billingAddress !== null && $shippingAddress !== null
         && ($billingAddress->street !== $shippingAddress->street
             || $billingAddress->city !== $shippingAddress->city
             || $billingAddress->postcode !== $shippingAddress->postcode))
        <section class="mt-4 rounded-control border border-ink-200 bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Billing address</h2>
            <address class="mt-3 text-sm not-italic text-ink-700">
                {{ $billingAddress->first_name }} {{ $billingAddress->last_name }}<br>
                @if ($billingAddress->street !== null){{ $billingAddress->street }}<br>@endif
                {{ $billingAddress->postcode }} {{ $billingAddress->city }}<br>
                {{ $billingAddress->country }}
            </address>
        </section>
    @endif

    {{-- ── Payment ─────────────────────────────────────────────────── --}}
    <section class="mt-4 rounded-control border border-ink-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Payment</h2>
        <p class="mt-3 text-sm text-ink-700">
            @if ($order->payment === null)
                Not recorded.
            @else
                {{ $order->payment->method->getLabel() }} — {{ $order->payment->status->getLabel() }}
            @endif
        </p>
    </section>
</div>
