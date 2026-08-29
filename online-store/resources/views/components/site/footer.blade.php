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
                    <span class="grid h-9 w-9 place-items-center rounded-card bg-marine-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 9V7a5 5 0 0 1 10 0v2" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <path d="M4.5 9h15l-1.2 10.2a2 2 0 0 1-2 1.8H7.7a2 2 0 0 1-2-1.8L4.5 9Z"
                                  stroke="white" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="text-[0.95rem] font-semibold tracking-tight text-white">Online&nbsp;Shop</span>
                </div>

                <p class="mt-4 max-w-xs text-sm leading-relaxed text-ink-400">
                    Considered tools, audio and outdoor gear. Shipped across Bulgaria
                    with Econt and Speedy.
                </p>

                <div class="mt-6 flex items-center gap-3 text-xs text-ink-400">
                    <span class="rounded border border-ink-700 px-2 py-1">Card</span>
                    <span class="rounded border border-ink-700 px-2 py-1">Cash on delivery</span>
                </div>
            </div>

            @foreach ($columns as $heading => $items)
                <nav aria-label="{{ $heading }}">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.15em] text-white">{{ $heading }}</h2>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($items as [$label, $href])
                            <li>
                                <a
                                    href="{{ $href }}"
                                    class="group inline-flex text-sm text-ink-400 transition-colors duration-200
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
            <p>&copy; {{ date('Y') }} Online Shop. All prices include VAT.</p>
            <p>Built for Lumen101 — Team B.</p>
        </div>
    </div>
</footer>
