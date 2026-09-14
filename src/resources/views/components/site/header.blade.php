@php
    $links = [
        ['label' => 'Journal', 'href' => '/journal', 'match' => 'journal*'],
        ['label' => 'About', 'href' => '/about', 'match' => 'about'],
    ];

    // Queried on every page, not only /catalogue — the mega-menu lives in
    // the header, which every page includes. 11 top-level rows with their
    // children eager-loaded (~50 rows total in the seeded catalogue) is one
    // cheap query; nothing here justifies a cache layer yet.
    $categoryMenu = \App\Support\Resolvers\ResolveCategoryFamily::topLevelWithChildren();
@endphp

<header
    x-data="{ open: false }"
    class="sticky top-0 z-40 border-b border-ink-200/80 bg-ink-50/85 backdrop-blur-md"
>
    <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">

        <a
            href="/"
            class="group flex shrink-0 items-center gap-2.5 rounded-control
                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
            aria-label="Amazoff — home"
        >
            <img
                src="{{ asset('images/logo.png') }}"
                alt=""
                class="h-9 w-9 rounded-card object-cover"
            >
            <x-site.wordmark />
        </a>

        {{-- Desktop nav --}}
        <nav class="hidden md:flex md:items-center md:gap-1" aria-label="Primary">

            {{-- Catalogue mega-menu. Hover (or focus, or tap on touch)
                 reveals every top-level category on the left; hovering one
                 shows its own children on the right — the "master category,
                 then its subnodes" shape, not the old flat leaf-only
                 dropdown. Clicking a top-level row with no highlighted
                 child still navigates it directly: a category can hold
                 products of its own alongside its children's (Garden holds
                 3 directly, on top of Mowers' and Watering's — §ResolveCategoryFamily). --}}
            <div
                x-data="{ open: false, hovered: {{ $categoryMenu->first()?->id ?? 'null' }} }"
                x-on:mouseleave="open = false"
                class="relative"
            >
                <a
                    href="/catalogue"
                    x-on:mouseenter="open = true"
                    x-on:focus="open = true"
                    @if (request()->is('catalogue*')) aria-current="page" @endif
                    aria-haspopup="true"
                    x-bind:aria-expanded="open ? 'true' : 'false'"
                    class="relative flex items-center gap-1 rounded-control px-3 py-2 text-sm font-medium
                           transition-colors duration-200 focus:outline-none
                           focus-visible:ring-4 focus-visible:ring-marine-600/20
                           {{ request()->is('catalogue*') ? 'text-ink-900' : 'text-ink-500 hover:text-ink-900' }}"
                >
                    Catalogue
                    <svg class="h-3.5 w-3.5 text-ink-400" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-3 -bottom-px h-0.5 origin-center rounded-full bg-marine-600
                               transition-transform duration-300
                               {{ request()->is('catalogue*') ? 'scale-x-100' : 'scale-x-0' }}"
                    ></span>
                </a>

                <div
                    x-cloak x-show="open" x-transition.opacity.duration.150ms
                    class="absolute left-0 top-full z-50 flex w-[36rem] overflow-hidden rounded-card
                           border border-ink-200 bg-white shadow-xl"
                >
                    <ul class="w-56 shrink-0 border-r border-ink-100 bg-ink-50/60 py-2">
                        @foreach ($categoryMenu as $topCategory)
                            <li>
                                <a
                                    href="/catalogue?category={{ $topCategory->slug }}"
                                    x-on:mouseenter="hovered = {{ $topCategory->id }}"
                                    x-bind:class="hovered === {{ $topCategory->id }}
                                        ? 'bg-white text-ink-900'
                                        : 'text-ink-600 hover:bg-white/70 hover:text-ink-900'"
                                    class="flex items-center justify-between px-4 py-2 text-sm font-medium
                                           transition-colors duration-150"
                                >
                                    {{ $topCategory->name }}
                                    @if ($topCategory->children->isNotEmpty())
                                        <svg class="h-3.5 w-3.5 text-ink-300" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                                        </svg>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex-1 p-4">
                        @foreach ($categoryMenu as $topCategory)
                            <div x-show="hovered === {{ $topCategory->id }}" x-cloak>
                                @if ($topCategory->children->isNotEmpty())
                                    <p class="px-2 text-[0.7rem] font-semibold uppercase tracking-wider text-ink-400">
                                        {{ $topCategory->name }}
                                    </p>
                                    <ul class="mt-1 grid grid-cols-2 gap-x-4">
                                        @foreach ($topCategory->children as $child)
                                            <li>
                                                <a
                                                    href="/catalogue?category={{ $child->slug }}"
                                                    class="block rounded-control px-2 py-1.5 text-sm text-ink-600
                                                           transition-colors duration-150
                                                           hover:bg-ink-50 hover:text-marine-700"
                                                >
                                                    {{ $child->name }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="px-2 py-1.5 text-sm text-ink-500">
                                        No subcategories — browse
                                        <a href="/catalogue?category={{ $topCategory->slug }}"
                                           class="font-medium text-marine-700 hover:underline">
                                            all of {{ $topCategory->name }}
                                        </a>.
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

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

            {{-- Cart. A Livewire island inside a static header: it is the
                 only part of the chrome that has to react to something
                 (`cart-updated`), and making the whole header live would put
                 the category menu's query behind every cart change. --}}
            <livewire:cart.cart-badge />

            {{-- Account. The admin link is gated by the very same
                 canAccessPanel() that guards the panel, not by a separate role
                 check — one source of truth, so the link cannot advertise a
                 door the gate then refuses. A hidden button is not security;
                 this is only the affordance. --}}
            @auth
                <div class="relative hidden sm:block" x-data="{ menu: false }">
                    <button
                        type="button"
                        x-on:click="menu = ! menu"
                        :aria-expanded="menu ? 'true' : 'false'"
                        class="inline-flex items-center gap-2 rounded-control px-3 py-2 text-sm font-medium
                               text-ink-600 transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900
                               focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
                    >
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-ink-900 text-[0.65rem] font-semibold text-white">
                            {{ mb_strtoupper(mb_substr(auth()->user()->first_name, 0, 1)) }}
                        </span>
                        <span class="max-w-[8rem] truncate">{{ auth()->user()->first_name }}</span>
                    </button>

                    <div
                        x-cloak x-show="menu" x-on:click.outside="menu = false" x-transition
                        class="absolute right-0 mt-2 w-56 overflow-hidden rounded-card border border-ink-200
                               bg-white py-1 shadow-lg"
                    >
                        @if (auth()->user()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
                            <a href="/admin"
                               class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-marine-700
                                      hover:bg-ink-50">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M4 5h16M4 12h16M4 19h10" />
                                </svg>
                                Admin panel
                            </a>
                            <div class="my-1 border-t border-ink-100"></div>
                        @endif

                        <a href="/account/profile" wire:navigate
                           class="block px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50">
                            Your profile
                        </a>

                        <a href="/account/orders" wire:navigate
                           class="block px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50">
                            Your orders
                        </a>

                        <a href="/account/addresses" wire:navigate
                           class="block px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50">
                            Your addresses
                        </a>

                        <a href="/wishlist" wire:navigate
                           class="block px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50">
                            Wishlist
                        </a>

                        <a href="/account/password" wire:navigate
                           class="block px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50">
                            Change password
                        </a>

                        <form method="POST" action="/logout">
                            @csrf
                            <button type="submit"
                                    class="block w-full px-4 py-2.5 text-left text-sm text-ink-700 hover:bg-ink-50">
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <a
                    href="/login"
                    class="hidden rounded-control px-3 py-2 text-sm font-medium text-ink-600
                           transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900
                           focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20 sm:inline-flex"
                >
                    Sign in
                </a>
            @endauth

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

            @auth
                <div class="mt-2 border-t border-ink-200 pt-2">
                    @if (auth()->user()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
                        <a href="/admin"
                           class="block rounded-control px-3 py-2.5 text-sm font-medium text-marine-700
                                  hover:bg-ink-100">
                            Admin panel
                        </a>
                    @endif
                    <a href="/account/profile"
                       class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                              hover:bg-ink-100 hover:text-ink-900">
                        Your profile
                    </a>
                    <a href="/account/orders"
                       class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                              hover:bg-ink-100 hover:text-ink-900">
                        Your orders
                    </a>
                    <a href="/account/addresses"
                       class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                              hover:bg-ink-100 hover:text-ink-900">
                        Your addresses
                    </a>
                    <a href="/wishlist"
                       class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                              hover:bg-ink-100 hover:text-ink-900">
                        Wishlist
                    </a>
                    <a href="/account/password"
                       class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                              hover:bg-ink-100 hover:text-ink-900">
                        Change password
                    </a>
                    <form method="POST" action="/logout">
                        @csrf
                        <button type="submit"
                                class="block w-full rounded-control px-3 py-2.5 text-left text-sm font-medium
                                       text-ink-600 hover:bg-ink-100 hover:text-ink-900">
                            Sign out
                        </button>
                    </form>
                </div>
            @else
                <a href="/login"
                   class="block rounded-control px-3 py-2.5 text-sm font-medium text-ink-600
                          transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900">
                    Sign in
                </a>
            @endauth
        </nav>
    </div>
</header>
