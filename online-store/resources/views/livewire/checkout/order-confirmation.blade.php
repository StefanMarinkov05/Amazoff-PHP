<div class="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Thank you</h1>
    <p class="mt-2 text-sm text-ink-500">
        Order <span class="font-medium text-ink-800">{{ $order->serial_number }}</span> is placed.
        A confirmation is on its way to {{ $order->email }}.
    </p>

    {{-- The redirect from Stripe is not proof of payment — the webhook is.
         So this reports what the row actually says, including the honest
         "still confirming" case. --}}
    <div class="mt-8 rounded-control border border-ink-200 bg-white p-5">
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between">
                <dt class="text-ink-500">Order status</dt>
                <dd class="font-medium text-ink-800">{{ $order->status->getLabel() }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-ink-500">Payment</dt>
                <dd class="font-medium text-ink-800">
                    @if ($payment === null)
                        Not recorded
                    @else
                        {{ $payment->method->getLabel() }} — {{ $payment->status->getLabel() }}
                    @endif
                </dd>
            </div>
            <div class="flex justify-between border-t border-ink-200 pt-3 text-base font-semibold">
                <dt class="text-ink-900">Total</dt>
                <dd class="text-ink-900">{{ $order->total_amount }}</dd>
            </div>
        </dl>

        @if ($payment?->status === \App\Enums\PaymentStatus::Pending && $payment->method === \App\Enums\PaymentMethod::Stripe)
            <p class="mt-4 rounded-control border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                We are still confirming your payment with the bank. This usually takes a few seconds — the order is
                already reserved, and nothing more is needed from you.
            </p>
        @endif
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-500">What you ordered</h2>

    <ul class="mt-4 divide-y divide-ink-200 rounded-control border border-ink-200 bg-white">
        @foreach ($items as $item)
            <li class="flex items-baseline justify-between gap-4 px-4 py-3 text-sm">
                <span class="text-ink-800">
                    {{ $item->product_name }}
                    <span class="text-ink-400">× {{ $item->quantity }}</span>
                </span>
                <span class="shrink-0 text-ink-700">{{ $item->line_total }}</span>
            </li>
        @endforeach
    </ul>

    <a href="/catalogue" wire:navigate
       class="mt-8 inline-block rounded-control border border-ink-300 px-4 py-2.5 text-sm font-medium text-ink-800
              hover:bg-ink-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
        Continue shopping
    </a>
</div>
