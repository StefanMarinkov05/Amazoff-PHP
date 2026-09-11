<div class="mx-auto w-full max-w-2xl px-4 py-12 sm:px-6">

    <a href="{{ route('account.orders.show', $order) }}" wire:navigate
       class="text-sm font-medium text-marine-700 underline-offset-4 hover:underline
              focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 rounded-sm">
        ← Back to order {{ $order->serial_number }}
    </a>

    <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink-900">
        Request a return
    </h1>
    <p class="mt-2 text-sm text-ink-500">
        You may withdraw from this order within {{ $withdrawalDays }} days of delivery
        (Consumer Rights Directive, Arts. 9–15). Choose the items and quantities,
        tell us why, and we will review the request.
    </p>

    @if ($submitted !== null)
        <div role="status"
             class="mt-6 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $submitted }}
        </div>
    @endif

    @if (! $windowOpen)
        <div class="mt-6 rounded-card border border-ink-200 bg-white px-4 py-3 text-sm text-ink-600">
            This order is not eligible for a return — it has either not been delivered
            or the {{ $withdrawalDays }}-day withdrawal period has passed.
            If you think this is wrong, please
            <a href="{{ route('contact') }}" class="underline underline-offset-4">contact us</a>.
        </div>
    @else
        <form wire:submit="submit" class="mt-6 space-y-5 rounded-card border border-ink-200 bg-white p-5" novalidate>
            <fieldset>
                <legend class="text-sm font-semibold text-ink-900">Items</legend>
                <ul class="mt-3 divide-y divide-ink-200">
                    @foreach ($order->orderItems as $item)
                        @php($max = $returnable[$item->id] ?? 0)
                        <li wire:key="line-{{ $item->id }}" class="flex items-center justify-between gap-4 py-3 text-sm">
                            <span class="min-w-0 text-ink-800">
                                {{ $item->product_name }}
                                @if ($item->variation_name)
                                    <span class="text-ink-400">— {{ $item->variation_name }}</span>
                                @endif
                                <span class="block text-xs text-ink-400">
                                    ordered {{ $item->quantity }},
                                    {{ $max }} still returnable
                                </span>
                            </span>
                            <input type="number" min="0" max="{{ $max }}"
                                   wire:model="quantities.{{ $item->id }}"
                                   @disabled($max === 0)
                                   class="w-20 rounded-control border border-ink-300 bg-white px-3 py-2 text-sm
                                          text-ink-900 focus:border-marine-600 focus:outline-none
                                          focus:ring-4 focus:ring-marine-600/20 disabled:bg-ink-50 disabled:text-ink-400">
                        </li>
                    @endforeach
                </ul>
            </fieldset>

            <div>
                <label for="reason" class="block text-sm font-medium text-ink-800">Reason</label>
                <textarea wire:model="reason" id="reason" rows="4"
                          class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                                 text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                                 focus:ring-4 focus:ring-marine-600/20"></textarea>
                @error('reason')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-1.5 text-xs text-ink-400">
                    You do not have to give a reason to exercise the right of withdrawal, but it helps us improve.
                </p>
            </div>

            <button type="submit"
                    class="rounded-control bg-marine-700 px-4 py-2 text-sm font-medium text-white
                           hover:bg-marine-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
                Submit return request
            </button>

            <p class="text-xs text-ink-400">
                You can also use the
                <a href="{{ route('returns.withdrawal-form') }}"
                   class="underline underline-offset-4 hover:text-ink-600">model withdrawal form</a>.
            </p>
        </form>
    @endif
</div>
