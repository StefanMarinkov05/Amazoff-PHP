{{-- Plain view, not a Livewire component: this page holds no state and runs
     no query, so a component would be pattern-following. ADR-0014. --}}
<x-layouts.app title="About">
    <div class="bg-ink-50">

        {{-- ── Hero ──────────────────────────────────────────────────── --}}
        <section class="relative overflow-hidden border-b border-ink-200 bg-ink-950">
            {{-- Two offset radial washes rather than a linear gradient: a
                 corner-to-corner gradient is the tell of a generic template. --}}
            <div aria-hidden="true"
                 class="pointer-events-none absolute -left-32 -top-40 h-[28rem] w-[28rem] rounded-full
                        bg-marine-600/25 blur-3xl"></div>
            <div aria-hidden="true"
                 class="pointer-events-none absolute -bottom-52 right-0 h-[26rem] w-[26rem] rounded-full
                        bg-marine-300/15 blur-3xl"></div>

            <div class="relative mx-auto max-w-5xl px-4 py-20 sm:px-6 lg:py-28">
                <p class="animate-card-in text-xs font-medium uppercase tracking-[0.2em] text-marine-300">
                    Lumen101 — Team B
                </p>

                <h1 class="animate-card-in mt-4 max-w-3xl text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl"
                    style="animation-delay: 80ms">
                    A small shop for
                    <span class="text-marine-300">things worth keeping</span>.
                </h1>

                <p class="animate-card-in mt-6 max-w-xl text-base leading-relaxed text-ink-300"
                   style="animation-delay: 160ms">
                    We stock audio, tools, kitchen and outdoor gear chosen one item at
                    a time — not by filling a category. If it will not last, it does
                    not go on the shelf.
                </p>

                <div class="animate-card-in mt-9 flex flex-wrap gap-3" style="animation-delay: 240ms">
                    <a href="/catalogue"
                       class="group inline-flex items-center gap-2 rounded-control bg-marine-600 px-5 py-3
                              text-sm font-semibold text-white transition-all duration-200
                              hover:-translate-y-0.5 hover:bg-marine-500 hover:shadow-lg hover:shadow-marine-600/30
                              focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-300/40">
                        Browse the catalogue
                        <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3" />
                        </svg>
                    </a>

                    <a href="/contact"
                       class="inline-flex items-center rounded-control border border-ink-700 px-5 py-3
                              text-sm font-semibold text-ink-200 transition-all duration-200
                              hover:-translate-y-0.5 hover:border-marine-300 hover:text-white
                              focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-300/40">
                        Talk to us
                    </a>
                </div>
            </div>
        </section>

        {{-- ── Figures ───────────────────────────────────────────────── --}}
        <section class="border-b border-ink-200 bg-white">
            <div class="mx-auto grid max-w-5xl gap-px bg-ink-200 px-4 sm:px-6 md:grid-cols-3">
                @foreach ([
                    ['2026', 'Trading since', 'Built as an internship project, run like a shop.'],
                    ['2', 'Couriers', 'Econt and Speedy, nationwide across Bulgaria.'],
                    ['14 days', 'Returns', 'Unused and in its packaging, no questions asked.'],
                ] as $index => [$figure, $label, $note])
                    <div class="animate-card-in bg-white px-6 py-10"
                         style="animation-delay: {{ $index * 90 }}ms">
                        <p class="text-3xl font-semibold tracking-tight text-marine-700">{{ $figure }}</p>
                        <p class="mt-1 text-xs font-medium uppercase tracking-[0.14em] text-ink-400">{{ $label }}</p>
                        <p class="mt-3 text-sm leading-relaxed text-ink-600">{{ $note }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── Story ─────────────────────────────────────────────────── --}}
        <section class="mx-auto max-w-5xl px-4 py-20 sm:px-6">
            <div class="grid gap-12 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-ink-900">How we choose</h2>
                    <div aria-hidden="true" class="mt-4 h-1 w-12 rounded-full bg-marine-600"></div>
                </div>

                <div class="space-y-5 text-[0.95rem] leading-relaxed text-ink-600">
                    <p>
                        Every product here has been used by someone on the team before it
                        was listed. That is a slower way to build a catalogue and it is
                        why ours is measured in hundreds rather than hundreds of
                        thousands.
                    </p>
                    <p>
                        We would rather carry one kettle that pours properly than nine
                        that nearly do. Where a product comes in several colours or
                        sizes we stock the ones people actually ask for, and we say so
                        on the page when something is down to its last few.
                    </p>
                    <p>
                        Prices include VAT, and the number on the product page is the
                        number you pay. Delivery is calculated at checkout from the
                        courier's own rates — we do not mark it up.
                    </p>
                </div>
            </div>
        </section>

        {{-- ── Promises ──────────────────────────────────────────────── --}}
        <section class="border-t border-ink-200 bg-white">
            <div class="mx-auto max-w-5xl px-4 py-20 sm:px-6">
                <h2 class="text-2xl font-semibold tracking-tight text-ink-900">What you can count on</h2>

                <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['M12 3 2 8l10 5 10-5-10-5Z M2 16l10 5 10-5 M2 12l10 5 10-5', 'Delivery, both couriers', 'Choose Econt or Speedy at checkout, to an address or an office. You are given the tracking number as soon as the parcel is handed over.'],
                        ['M9 12.75 11.25 15 15 9.75 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'Pay how you prefer', 'Card online, or cash on delivery. Cash orders reserve the stock the moment they are confirmed, exactly like a paid one.'],
                        ['M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0Z M12 7v5l3 2', 'Fourteen days to change your mind', 'Unused and in its original packaging. We refund to the method you paid with, once the parcel is back with us.'],
                        ['M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25', 'Honest product pages', 'Stock counts are live and already exclude items held in someone else\'s basket. If a page says two left, there are two.'],
                        ['M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z', 'Your details stay yours', 'We keep what an order needs and nothing else. No selling data on, no newsletter you did not ask for.'],
                        ['M8 10.5h8m-8 3.5h5m6-8.25v10.5a2.25 2.25 0 0 1-2.25 2.25H8.25L3 21.75V6.25A2.25 2.25 0 0 1 5.25 4h13.5A2.25 2.25 0 0 1 21 6.25Z', 'A person answers', 'Messages reach a real inbox and we reply within one working day. No ticket numbers, no phone tree.'],
                    ] as $index => [$path, $title, $body])
                        <article class="group animate-card-in rounded-card border border-ink-200 bg-ink-50 p-6
                                        transition-all duration-300 hover:-translate-y-1
                                        hover:border-marine-600/50 hover:bg-white hover:shadow-xl hover:shadow-ink-900/10"
                                 style="animation-delay: {{ min($index * 70, 400) }}ms">
                            <span class="grid h-11 w-11 place-items-center rounded-control bg-marine-600/10
                                         text-marine-700 transition-colors duration-300
                                         group-hover:bg-marine-600 group-hover:text-white">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                     stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}" />
                                </svg>
                            </span>

                            <h3 class="mt-5 text-sm font-semibold text-ink-900">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-ink-600">{{ $body }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── Closing ───────────────────────────────────────────────── --}}
        <section class="mx-auto max-w-5xl px-4 py-20 sm:px-6">
            <div class="relative overflow-hidden rounded-card bg-ink-950 px-8 py-14 text-center sm:px-12">
                <div aria-hidden="true"
                     class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full
                            bg-marine-600/25 blur-3xl"></div>

                <div class="relative">
                    <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                        Something you cannot find?
                    </h2>
                    <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-ink-300">
                        Tell us what you are after. If we can source it well, we will —
                        and if we cannot, we will say so rather than sell you a
                        substitute.
                    </p>

                    <a href="/contact"
                       class="group mt-8 inline-flex items-center gap-2 rounded-control bg-white px-5 py-3
                              text-sm font-semibold text-ink-900 transition-all duration-200
                              hover:-translate-y-0.5 hover:bg-marine-300 hover:shadow-lg
                              focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-300/40">
                        Send us a message
                        <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3" />
                        </svg>
                    </a>
                </div>
            </div>
        </section>
    </div>
</x-layouts.app>
