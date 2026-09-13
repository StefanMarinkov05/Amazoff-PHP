@php
    $featured = $this->featuredProducts;
    $discounted = $this->discountedProducts;
    $newArrivals = $this->newProducts;
    $popular = $this->popularProducts;
    $articles = $this->latestArticles;
@endphp

<div>
    {{-- ── Banner ──────────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden bg-ink-950">
        <div
            aria-hidden="true"
            class="absolute inset-0 opacity-40"
            style="background: radial-gradient(circle at 15% 20%, oklch(0.55 0.17 40 / 0.5) 0%, transparent 55%),
                                radial-gradient(circle at 85% 80%, oklch(0.45 0.15 230 / 0.6) 0%, transparent 60%);"
        ></div>

        <div class="relative mx-auto flex max-w-7xl flex-col gap-8 px-4 py-16 sm:px-6 sm:py-24 md:flex-row md:items-stretch lg:px-8">
            {{-- Logo, sized to the text column beside it: fixed and centered
                 on mobile, stretched to the row's own height (the text
                 column's) from md up. object-contain keeps the mark from
                 distorting at whatever height that resolves to. --}}
            <div class="h-32 w-32 shrink-0 self-center sm:h-40 sm:w-40 md:h-auto md:w-44 md:self-stretch lg:w-52">
                <img
                    src="{{ asset('images/logo.png') }}"
                    alt=""
                    width="1254" height="1254" fetchpriority="high"
                    class="h-full w-full object-contain"
                >
            </div>

            <div class="flex flex-1 flex-col justify-center">
                <x-site.wordmark tone="light" />
                <h1 class="mt-3 max-w-xl text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                    Considered tools, audio, and outdoor gear.
                </h1>
                <p class="mt-4 max-w-lg text-sm leading-relaxed text-ink-300">
                    Shipped across Bulgaria with Econt and Speedy. Real reviews from customers who bought the thing.
                </p>
                <a href="/catalogue" wire:navigate
                   class="mt-8 inline-flex items-center gap-2 self-start rounded-control bg-white px-5 py-2.5 text-sm font-semibold
                          text-ink-900 transition-colors duration-200 hover:bg-brand-orange hover:text-white">
                    Browse the catalogue
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl space-y-16 px-4 py-14 sm:px-6 lg:px-8">

        {{-- ── Featured (administrator-controlled) ────────────────── --}}
        @if ($featured->isNotEmpty())
            <x-home.product-section
                title="Featured"
                subtitle="Picked by our team."
                :products="$featured"
                :price="[$this, 'price']"
            />
        @endif

        {{-- ── On sale ─────────────────────────────────────────────── --}}
        @if ($discounted->isNotEmpty())
            <x-home.product-section
                title="On sale"
                subtitle="Real discounts, live right now."
                :products="$discounted"
                :price="[$this, 'price']"
            />
        @endif

        {{-- ── New arrivals ────────────────────────────────────────── --}}
        @if ($newArrivals->isNotEmpty())
            <x-home.product-section
                title="New arrivals"
                subtitle="Just added to the catalogue."
                :products="$newArrivals"
                :price="[$this, 'price']"
            />
        @endif

        {{-- ── Popular ─────────────────────────────────────────────── --}}
        @if ($popular->isNotEmpty())
            <x-home.product-section
                title="Popular"
                subtitle="Most reviewed by customers who bought them."
                :products="$popular"
                :price="[$this, 'price']"
            />
        @endif

        {{-- ── Latest articles ─────────────────────────────────────── --}}
        @if ($articles->isNotEmpty())
            <section>
                <div class="flex items-baseline justify-between">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight text-ink-900">From the journal</h2>
                        <p class="mt-1 text-sm text-ink-500">Guides and the odd deep-dive.</p>
                    </div>
                    <a href="/journal" wire:navigate
                       class="text-sm font-medium text-marine-700 underline-offset-4 hover:underline">
                        All articles
                    </a>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($articles as $article)
                        <a href="/journal/{{ $article->slug }}" wire:navigate wire:key="home-article-{{ $article->id }}"
                           class="group block overflow-hidden rounded-card border border-ink-200 bg-white
                                  transition-shadow duration-200 hover:shadow-lg">
                            <x-journal.cover :article="$article" class="aspect-[4/3]" />
                            <div class="p-3.5">
                                @if ($article->articleCategory)
                                    <p class="text-[0.65rem] font-medium uppercase tracking-[0.1em] text-ink-400">
                                        {{ $article->articleCategory->name }}
                                    </p>
                                @endif
                                <h3 class="mt-1 line-clamp-2 text-sm font-medium leading-snug text-ink-900
                                           group-hover:text-marine-700">
                                    {{ $article->title }}
                                </h3>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
