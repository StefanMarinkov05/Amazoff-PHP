@php
    use App\Support\ResolveVariationImage;
    use App\Support\ResolveVariationPrice;

    $items = $this->items;
    $totals = $this->totals;
    $count = $items->sum('quantity');
    $coupon = $this->cart->coupon;
@endphp

<div class="bg-ink-50">
    <div class="mx-auto max-w-6xl px-4 py-8">

        {{-- ── Checkout progress ──────────────────────────────────────── --}}
        @php($steps = ['Basket', 'Details', 'Payment', 'Done'])
        <ol class="mb-10 flex items-center justify-center gap-2 sm:gap-4">
            @foreach ($steps as $i => $step)
                <li class="flex items-center gap-2 sm:gap-4" @if ($i === 0) aria-current="step" @endif>
                    <div class="flex flex-col items-center gap-1.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold
                                     transition-colors duration-300
                                     {{ $i === 0
                                          ? 'bg-ink-950 text-white'
                                          : 'border border-ink-300 bg-white text-ink-400' }}">
                            {{ $i + 1 }}
                        </span>
                        <span class="text-[0.65rem] font-medium uppercase tracking-[0.12em]
                                     {{ $i === 0 ? 'text-ink-950' : 'text-ink-400' }}">
                            {{ $step }}
                        </span>
                    </div>

                    @unless ($loop->last)
                        <span aria-hidden="true" class="mb-5 h-px w-6 bg-ink-300 sm:w-14"></span>
                    @endunless
                </li>
            @endforeach
        </ol>

        <div class="mb-5 flex items-baseline justify-between border-b-2 border-ink-900 pb-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">Your basket</h1>
            <span class="text-sm text-ink-500">
                {{ $count }} {{ Str::plural('item', $count) }}
            </span>
        </div>

        @if ($items->isEmpty())
            {{-- ── Empty ──────────────────────────────────────────────── --}}
            <div class="border border-dashed border-ink-300 bg-white px-6 py-24 text-center">
                <svg class="mx-auto h-12 w-12 text-ink-300" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.25" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                </svg>
                <p class="mt-5 text-lg font-semibold text-ink-900">Nothing in here yet</p>
                <p class="mt-1.5 text-sm text-ink-500">Have a look at what's in stock.</p>
                <a href="/catalogue"
                   class="mt-7 inline-flex items-center gap-2 bg-ink-950 px-6 py-3 text-sm font-semibold
                          text-white transition-colors duration-200 hover:bg-marine-700
                          focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25">
                    Browse the catalogue
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            </div>
        @else
            <div class="grid gap-8 lg:grid-cols-[1fr_22rem]">

                {{-- ── Lines ──────────────────────────────────────────── --}}
                <div wire:loading.class="opacity-50" class="transition-opacity duration-200">
                    <div class="hidden grid-cols-12 gap-4 border-b border-ink-200 pb-2
                                text-[0.65rem] font-semibold uppercase tracking-[0.15em] text-ink-400 sm:grid">
                        <span class="col-span-6">Product</span>
                        <span class="col-span-3 text-center">Quantity</span>
                        <span class="col-span-3 text-right">Price</span>
                    </div>

                    @foreach ($items as $index => $item)
                        @php($variation = $item->productVariation)
                        @php($product = $variation?->product)
                        @continue($product === null)

                        @php($unit = ResolveVariationPrice::current($variation))
                        @php($inventory = $variation->inventory)
                        @php($available = $inventory ? max(0, $inventory->current_quantity - $inventory->reserved_quantity) : 0)

                        <div
                            wire:key="item-{{ $item->id }}"
                            style="animation-delay: {{ min($index * 50, 300) }}ms"
                            class="animate-rise-in grid grid-cols-12 items-center gap-4 border-b border-ink-200 py-5"
                        >
                            {{-- Product --}}
                            <div class="col-span-12 flex gap-4 sm:col-span-6">
                                <a href="/products/{{ $product->slug }}"
                                   class="group relative h-24 w-24 shrink-0 overflow-hidden border border-ink-200 bg-white">
                                    <img src="{{ ResolveVariationImage::urlOrDefault($variation) }}"
                                         alt="{{ $product->name }}" loading="lazy"
                                         class="h-full w-full object-cover transition-transform duration-500
                                                ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-105">
                                </a>

                                <div class="min-w-0 flex-1">
                                    <a href="/products/{{ $product->slug }}"
                                       class="text-base font-semibold leading-snug text-ink-950 transition-colors
                                              duration-200 hover:text-marine-700">
                                        {{ $product->name }}
                                    </a>

                                    @if ($variation->attributeValues->isNotEmpty())
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            @foreach ($variation->attributeValues as $value)
                                                <span wire:key="opt-{{ $item->id }}-{{ $value->id }}"
                                                      class="inline-flex items-center gap-1 border border-ink-200
                                                             px-1.5 py-0.5 text-[0.65rem] text-ink-500">
                                                    @if ($value->color_hex)
                                                        <span aria-hidden="true"
                                                              class="h-2.5 w-2.5 rounded-full ring-1 ring-ink-300"
                                                              style="background-color: {{ $value->color_hex }}"></span>
                                                    @endif
                                                    {{ $value->value }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    <p class="mt-2 flex items-center gap-1.5 text-[0.7rem]">
                                        @if ($available === 0)
                                            <span class="h-1.5 w-1.5 rounded-full bg-ink-300"></span>
                                            <span class="font-medium text-ink-500">Out of stock</span>
                                        @elseif ($available <= 3)
                                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-ember-500"></span>
                                            <span class="font-medium text-ember-700">Only {{ $available }} left</span>
                                        @else
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            <span class="font-medium text-emerald-700">In stock</span>
                                        @endif
                                    </p>

                                    <button
                                        type="button"
                                        wire:click="remove({{ $item->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="remove({{ $item->id }})"
                                        class="mt-2 text-[0.7rem] uppercase tracking-[0.1em] text-ink-400
                                               underline-offset-4 transition-colors duration-200
                                               hover:text-ember-600 hover:underline focus:outline-none
                                               focus-visible:text-ember-600"
                                    >Remove</button>
                                </div>
                            </div>

                            {{-- Quantity --}}
                            <div class="col-span-6 sm:col-span-3 sm:justify-self-center">
                                <div class="inline-flex items-center border border-ink-300 bg-white">
                                    <button
                                        type="button"
                                        wire:click="decrement({{ $item->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="decrement({{ $item->id }})"
                                        @disabled($item->quantity <= 1)
                                        aria-label="Decrease quantity"
                                        class="grid h-9 w-9 place-items-center text-ink-500 transition-colors duration-200
                                               hover:bg-ink-950 hover:text-white disabled:cursor-not-allowed
                                               disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-ink-500"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" d="M5 12h14" />
                                        </svg>
                                    </button>

                                    {{-- Typed as well as stepped. Debounced so a
                                         two-digit quantity is one request, not
                                         one per keystroke; `.number` keeps the
                                         bound value an int rather than a string.
                                         Spinners are hidden — the flanking
                                         buttons already do that job. --}}
                                    <label class="sr-only" for="qty-{{ $item->id }}">
                                        Quantity for {{ $product->name }}
                                    </label>
                                    <input
                                        id="qty-{{ $item->id }}"
                                        type="number"
                                        min="1"
                                        inputmode="numeric"
                                        wire:model.live.debounce.600ms.number="quantities.{{ $item->id }}"
                                        wire:loading.attr="disabled"
                                        wire:target="quantities.{{ $item->id }}"
                                        @error('line-'.$item->id) aria-invalid="true" aria-describedby="qty-error-{{ $item->id }}" @enderror
                                        class="h-9 w-12 border-x border-ink-300 bg-white text-center text-sm font-semibold
                                               tabular-nums text-ink-950 transition-colors duration-200
                                               focus:border-marine-600 focus:bg-marine-50 focus:outline-none
                                               disabled:opacity-50
                                               [appearance:textfield]
                                               [&::-webkit-inner-spin-button]:appearance-none
                                               [&::-webkit-outer-spin-button]:appearance-none"
                                    >

                                    <button
                                        type="button"
                                        wire:click="increment({{ $item->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="increment({{ $item->id }})"
                                        aria-label="Increase quantity"
                                        class="grid h-9 w-9 place-items-center text-ink-500 transition-colors
                                               duration-200 hover:bg-ink-950 hover:text-white"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                                        </svg>
                                    </button>
                                </div>

                                @error('line-'.$item->id)
                                    <p id="qty-error-{{ $item->id }}" role="alert"
                                       class="mt-2 max-w-[14rem] text-[0.7rem] leading-snug text-red-700">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            {{-- Price --}}
                            <div class="col-span-6 text-right sm:col-span-3">
                                <span class="text-lg font-semibold tabular-nums text-ink-950">
                                    €{{ number_format((float) $unit * $item->quantity, 2) }}
                                </span>
                                @if ($item->quantity > 1)
                                    <p class="mt-0.5 text-[0.7rem] tabular-nums text-ink-400">
                                        €{{ number_format((float) $unit, 2) }} each
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <a href="/catalogue"
                       class="group mt-6 inline-flex items-center gap-2 text-sm font-medium text-marine-700
                              transition-colors duration-200 hover:text-ember-600">
                        <svg class="h-4 w-4 transition-transform duration-300 group-hover:-translate-x-1"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                        Continue shopping
                    </a>
                </div>

                {{-- ── Summary ────────────────────────────────────────── --}}
                <aside class="lg:sticky lg:top-24 lg:self-start">
                    <div class="border border-ink-200 bg-white">

                        {{-- Coupon --}}
                        <div class="border-b border-ink-200 p-5">
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.15em] text-ink-400">
                                Discount code
                            </p>

                            @if ($coupon)
                                <div class="mt-3 flex items-center justify-between gap-2 border border-ember-400 bg-ember-50 px-3 py-2">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-ember-900">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                                        </svg>
                                        {{ $coupon->code }}
                                    </span>

                                    <button
                                        type="button"
                                        wire:click="removeCoupon"
                                        aria-label="Remove discount code"
                                        class="text-ember-700 transition-colors duration-200 hover:text-ember-900"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            @else
                                <form wire:submit="applyCoupon" class="mt-3 flex gap-2">
                                    <input
                                        type="text"
                                        wire:model="couponCode"
                                        placeholder="Enter code"
                                        aria-label="Discount code"
                                        @error('coupon') aria-invalid="true" aria-describedby="coupon-error" @enderror
                                        class="min-w-0 flex-1 border border-ink-300 bg-white px-3 py-2 text-sm uppercase
                                               tracking-wide placeholder:normal-case placeholder:tracking-normal
                                               placeholder:text-ink-300 focus:border-marine-600 focus:outline-none
                                               focus:ring-4 focus:ring-marine-600/10"
                                    >
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="applyCoupon"
                                        class="shrink-0 bg-ink-950 px-4 text-xs font-semibold uppercase tracking-[0.1em]
                                               text-white transition-colors duration-200 hover:bg-marine-700
                                               disabled:opacity-50 focus:outline-none focus-visible:ring-4
                                               focus-visible:ring-marine-600/25"
                                    >Apply</button>
                                </form>

                                @error('coupon')
                                    <p id="coupon-error" role="alert" class="mt-2 text-xs text-red-700">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>

                        {{-- Totals --}}
                        <div class="p-5">
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.15em] text-ink-400">
                                Order summary
                            </p>

                            <dl class="mt-4 space-y-2.5 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-ink-500">Subtotal</dt>
                                    <dd class="tabular-nums text-ink-800">€{{ number_format((float) $totals['subtotal'], 2) }}</dd>
                                </div>

                                <div class="flex justify-between">
                                    <dt class="text-ink-500">Delivery</dt>
                                    <dd class="text-xs text-ink-400">Calculated at checkout</dd>
                                </div>

                                <div class="flex justify-between border-t border-ink-200 pt-3">
                                    <dt class="text-base font-semibold text-ink-950">Total</dt>
                                    <dd class="text-xl font-bold tabular-nums text-ink-950">
                                        €{{ number_format((float) $totals['total'], 2) }}
                                    </dd>
                                </div>

                                {{-- Gross pricing: VAT is extracted from the total,
                                     never added to it. `CalculateCartTotals`. --}}
                                <div class="flex justify-between text-[0.7rem]">
                                    <dt class="text-ink-400">of which VAT</dt>
                                    <dd class="tabular-nums text-ink-400">€{{ number_format((float) $totals['vat'], 2) }}</dd>
                                </div>
                            </dl>

                            <button
                                type="button"
                                disabled
                                class="mt-5 flex w-full items-center justify-center gap-2 bg-ink-300 px-5 py-3.5
                                       text-sm font-semibold uppercase tracking-[0.1em] text-white"
                            >
                                Checkout
                            </button>
                            <p class="mt-2 text-center text-[0.7rem] text-ink-400">Checkout is not built yet</p>
                        </div>
                    </div>

                    <div class="mt-4 flex items-start gap-2 text-[0.7rem] leading-relaxed text-ink-400">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        Stock is held only once you check out — an item in your basket can still sell out.
                    </div>
                </aside>
            </div>
        @endif
    </div>
</div>
