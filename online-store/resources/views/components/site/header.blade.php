@php
    // Placeholder until the cart component exists — the badge is wired to a
    // real count in the cart slice, not faked with a random number here.
    $cartCount = 0;

    $links = [
        ['label' => 'Catalogue', 'href' => '/catalogue', 'match' => 'catalogue*'],
        ['label' => 'Journal', 'href' => '/journal', 'match' => 'journal*'],
        ['label' => 'About', 'href' => '/about', 'match' => 'about'],
    ];
@endphp

<header
    x-data="{ open: false }"
    class="sticky top-0 z-40 border-b border-ink-200/80 bg-ink-50/85 backdrop-blur-md"
>
    <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">

        {{-- Logo. A drawn mark, not an emoji — the "A" aperture doubles as a
             shopping bag handle. --}}
        <a
            href="/"
            class="group flex shrink-0 items-center gap-2.5 rounded-control
                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
            aria-label="Online Shop — home"
        >
            <span class="grid h-9 w-9 place-items-center rounded-card bg-ink-900
                         transition-colors duration-200 group-hover:bg-marine-700">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 9V7a5 5 0 0 1 10 0v2" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    <path d="M4.5 9h15l-1.2 10.2a2 2 0 0 1-2 1.8H7.7a2 2 0 0 1-2-1.8L4.5 9Z"
                          stroke="white" stroke-width="2" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="text-[0.95rem] font-semibold tracking-tight">Online&nbsp;Shop</span>
        </a>

        {{-- Desktop nav --}}
        <nav class="hidden md:flex md:items-center md:gap-1" aria-label="Primary">
            @foreach ($links as $link)
                @php($active = request()->is($link['match']))
                <a
                    href="{{ $link['href'] }}"
                    @if ($active) aria-current="page" @endif
                    class="relative rounded-control px-3 py-2 text-sm font-medium transition-colors duration-200
                           focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                           {{ $active ? 'text-ink-900' : 'text-ink-500 hover:text-ink-900' }}"
                >
                    {{ $link['label'] }}
                    {{-- Underline grows from centre on hover; sits full-width when active. --}}
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-3 -bottom-px h-0.5 origin-center rounded-full bg-marine-600
                               transition-transform duration-300
                               {{ $active ? 'scale-x-100' : 'scale-x-0 hover:scale-x-100' }}"
                    ></span>
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-1">

            {{-- Cart --}}
            <a
                href="/cart"
                class="group relative inline-flex items-center gap-2 rounded-control px-3 py-2 text-sm font-medium
                       text-ink-600 transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
            >
                <span class="relative">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>

                    @if ($cartCount > 0)
                        <span class="absolute -right-2 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full
                                     bg-marine-600 px-1 text-[0.6rem] font-semibold text-white">
                            {{ $cartCount }}
                        </span>
                    @endif
                </span>
                <span class="hidden sm:inline">Cart</span>
            </a>

            {{-- Account. Filament's panel gate already refuses non-staff, so this
                 points at the customer area rather than /admin. --}}
            <a
                href="/login"
                class="hidden rounded-control px-3 py-2 text-sm font-medium text-ink-600
                       transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 sm:inline-flex"
            >
                Sign in
            </a>

            {{-- Mobile toggle --}}
            <button
                type="button"
                x-on:click="open = ! open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="mobile-nav"
                class="inline-flex items-center justify-center rounded-control p-2 text-ink-600
                       transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 md:hidden"
            >
                <span class="sr-only">Toggle navigation</span>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" aria-hidden="true">
                    <path x-show="! open" stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    <path x-cloak x-show="open" stroke-linecap="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Mobile nav. Alpine ships with Livewire 3 — no separate include. --}}
    <div
        id="mobile-nav"
        x-cloak
        x-show="open"
        x-collapse
        class="border-t border-ink-200 bg-ink-50 md:hidden"
    >
        <nav class="mx-auto max-w-7xl space-y-1 px-4 py-4 sm:px-6" aria-label="Primary, mobile">
            @foreach ($links as $link)
                @php($active = request()->is($link['match']))
                <a
                    href="{{ $link['href'] }}"
                    @if ($active) aria-current="page" @endif
                    class="block rounded-control px-3 py-2.5 text-sm font-medium transition-colors duration-200
                           {{ $active ? 'bg-ink-100 text-ink-900' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}"
                >
                    {{ $link['label'] }}
                </a>
            @endforeach

            <a href="/login"
               class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                      transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900">
                Sign in
            </a>
        </nav>
    </div>
</header>
