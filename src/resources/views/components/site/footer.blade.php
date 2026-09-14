@php
    $columns = [
        'Shop' => [
            ['Catalogue', '/catalogue'],
            ['New arrivals', '/catalogue?sortBy=created_at&sortDir=desc'],
            ['Offers', '/catalogue'],
        ],
        'Help' => [
            ['Delivery', '/delivery'],
            ['Payment', '/payment-information'],
            ['Track an order', '/orders/track'],
            ['FAQ', '/faq'],
            ['Contact', '/contact'],
        ],
        'Legal' => [
            ['Terms', '/terms'],
            ['Privacy policy', '/privacy'],
            ['Cookie policy', '/cookies'],
        ],
    ];
@endphp

<footer class="mt-24 border-t border-ink-200 bg-ink-950 text-ink-300">
    <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">

        <div class="grid gap-12 md:grid-cols-[1.5fr_repeat(3,1fr)]">

            <div>
                <div class="flex items-center gap-2.5">
                    <img
                        src="{{ asset('images/logo.png') }}"
                        alt=""
                        class="h-9 w-9 rounded-card object-cover"
                    >
                    <x-site.wordmark tone="light" />
                </div>

                <p class="mt-4 max-w-xs text-sm leading-relaxed text-ink-400">
                    Considered tools, audio and outdoor gear. Shipped across Bulgaria
                    with Econt and Speedy.
                </p>

                <div class="mt-6 flex items-center gap-3 text-xs text-ink-400">
                    <span class="rounded border border-ink-700 px-2 py-1">Card</span>
                    <span class="rounded border border-ink-700 px-2 py-1">Cash on delivery</span>
                </div>

                <div class="mt-8 max-w-xs">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.15em] text-white">
                        Occasional post
                    </h2>
                    <p class="mt-2 text-xs leading-relaxed text-ink-400">
                        New arrivals and the odd guide. No more than once a month.
                    </p>
                    <div class="mt-3">
                        <livewire:contact.newsletter-signup />
                    </div>
                </div>
            </div>

            @foreach ($columns as $heading => $items)
                <nav aria-label="{{ $heading }}">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.15em] text-white">{{ $heading }}</h2>
                    {{-- WCAG 2.5.8 / EAA: each link is a 24px-minimum target.
                         `-my-1 py-1` grows the hit area without moving the text. --}}
                    <ul class="mt-3 space-y-0.5">
                        @foreach ($items as [$label, $href])
                            <li>
                                <a
                                    href="{{ $href }}"
                                    class="group -my-1 inline-flex min-h-[24px] items-center py-1 text-sm
                                           text-ink-400 transition-colors duration-200
                                           hover:text-white focus:outline-none focus-visible:ring-4
                                           focus-visible:ring-marine-600/30 rounded-sm"
                                >
                                    {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>

        <div class="mt-12 flex flex-col gap-3 border-t border-ink-800 pt-6 text-xs text-ink-500 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} Amazoff. All prices include VAT.</p>
            <p>Built for Lumen101 — Team B.</p>
        </div>
    </div>
</footer>
