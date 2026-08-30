@php
    $featured = $this->articles->first();
    $rest = $this->articles->slice(1);
    $filtered = $this->categoryId !== null || $this->tag !== null;
@endphp

<div class="bg-ink-50">

    {{-- ── Masthead ───────────────────────────────────────────────────── --}}
    <header class="relative overflow-hidden border-b border-ink-200 bg-ink-950">
        {{-- Two offset radial washes, slowly panning. The blue sits behind the
             orange so the warm edge reads as the light source. --}}
        <div aria-hidden="true" class="animate-drift pointer-events-none absolute inset-0 opacity-70">
            <div class="absolute -left-1/4 top-[-30%] h-[36rem] w-[36rem] rounded-full bg-marine-600/35 blur-3xl"></div>
            <div class="absolute right-[-10%] bottom-[-45%] h-[30rem] w-[30rem] rounded-full bg-ember-600/25 blur-3xl"></div>
        </div>

        <div class="relative mx-auto max-w-6xl px-4 py-16 sm:py-20">
            <p class="animate-rise-in text-[0.7rem] font-semibold uppercase tracking-[0.35em] text-ember-400">
                The Journal
            </p>

            <h1 class="animate-rise-in mt-4 max-w-2xl text-4xl font-semibold leading-[1.05] tracking-tight text-white sm:text-5xl"
                style="animation-delay: 80ms">
                Notes on the things<br>
                <span class="bg-gradient-to-r from-ember-400 to-marine-300 bg-clip-text text-transparent">
                    we sell
                </span>
            </h1>

            <p class="animate-rise-in mt-5 max-w-lg text-sm leading-relaxed text-ink-300" style="animation-delay: 160ms">
                Buying guides, workshop notes and the occasional strong opinion — written by the
                people who choose what goes in the catalogue.
            </p>
        </div>
    </header>

    <div class="mx-auto max-w-6xl px-4 py-10">

        {{-- ── Filters ────────────────────────────────────────────────── --}}
        <div class="mb-10 flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="$set('categoryId', null)"
                class="rounded-full px-3.5 py-1.5 text-xs font-medium transition-all duration-200
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25
                       {{ $this->categoryId === null
                            ? 'bg-ink-900 text-white'
                            : 'bg-white text-ink-600 ring-1 ring-ink-200 hover:ring-marine-600 hover:text-marine-700' }}"
            >
                All
            </button>

            @foreach ($this->categories as $category)
                <button
                    type="button"
                    wire:key="cat-{{ $category->id }}"
                    wire:click="$set('categoryId', {{ $category->id }})"
                    class="rounded-full px-3.5 py-1.5 text-xs font-medium transition-all duration-200
                           hover:-translate-y-0.5 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25
                           {{ $this->categoryId === $category->id
                                ? 'bg-marine-600 text-white shadow-md shadow-marine-600/25'
                                : 'bg-white text-ink-600 ring-1 ring-ink-200 hover:ring-marine-600 hover:text-marine-700' }}"
                >
                    {{ $category->name }}
                </button>
            @endforeach

            @if ($this->tag !== null)
                <button
                    type="button"
                    wire:click="$set('tag', null)"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-ember-100 px-3 py-1.5
                           text-xs font-medium text-ember-900 ring-1 ring-ember-200
                           transition-colors duration-200 hover:bg-ember-200"
                >
                    #{{ $this->tag }}
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            @endif
        </div>

        {{-- ── Featured ───────────────────────────────────────────────── --}}
        @if ($featured && ! $filtered && $this->articles->currentPage() === 1)
            <a
                href="/journal/{{ $featured->slug }}"
                wire:key="featured-{{ $featured->id }}"
                class="group animate-rise-in mb-12 grid overflow-hidden rounded-card border border-ink-200
                       bg-white transition-all duration-300 hover:-translate-y-1
                       hover:border-marine-600/40 hover:shadow-2xl hover:shadow-ink-900/10 md:grid-cols-2"
            >
                <div class="relative aspect-[16/10] overflow-hidden bg-ink-100 md:aspect-auto md:h-full">
                    @if ($featured->main_image_path)
                        <img src="{{ Storage::url($featured->main_image_path) }}" alt="{{ $featured->title }}"
                             class="h-full w-full object-cover transition-transform duration-700
                                    ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-105">
                    @endif
                    <span class="absolute left-4 top-4 rounded-full bg-ember-500 px-2.5 py-1
                                 text-[0.65rem] font-bold uppercase tracking-widest text-white shadow-lg">
                        Latest
                    </span>
                </div>

                <div class="flex flex-col justify-center p-6 sm:p-9">
                    @if ($featured->articleCategory)
                        <span class="text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-marine-700">
                            {{ $featured->articleCategory->name }}
                        </span>
                    @endif

                    <h2 class="mt-2.5 text-2xl font-semibold leading-tight tracking-tight text-ink-900
                               transition-colors duration-200 group-hover:text-marine-700 sm:text-3xl">
                        {{ $featured->title }}
                    </h2>

                    @if ($featured->summary)
                        <p class="mt-3 line-clamp-3 text-sm leading-relaxed text-ink-600">{{ $featured->summary }}</p>
                    @endif

                    <div class="mt-5 flex items-center gap-2 text-xs text-ink-400">
                        @if ($featured->author)
                            <span class="font-medium text-ink-600">{{ $featured->author->getFilamentName() }}</span>
                            <span aria-hidden="true">·</span>
                        @endif
                        <time datetime="{{ $featured->published_at?->toDateString() }}">
                            {{ $featured->published_at?->format('j M Y') }}
                        </time>
                    </div>

                    <span class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-marine-700">
                        Read the piece
                        <svg class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                        </svg>
                    </span>
                </div>
            </a>
        @endif

        {{-- ── Grid ───────────────────────────────────────────────────── --}}
        <div wire:loading.class="opacity-40" class="transition-opacity duration-200">
            @php($cards = ($filtered || $this->articles->currentPage() > 1) ? $this->articles : $rest)

            @if ($cards->count() > 0)
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cards as $index => $article)
                        <article
                            wire:key="article-{{ $article->id }}"
                            style="animation-delay: {{ min($index * 60, 400) }}ms"
                            class="group animate-rise-in flex flex-col overflow-hidden rounded-card border
                                   border-ink-200 bg-white transition-all duration-300
                                   hover:-translate-y-1.5 hover:border-ember-400/60 hover:shadow-xl hover:shadow-ink-900/10"
                        >
                            <a href="/journal/{{ $article->slug }}" class="relative block aspect-[16/10] overflow-hidden bg-ink-100">
                                @if ($article->main_image_path)
                                    <img src="{{ Storage::url($article->main_image_path) }}"
                                         alt="{{ $article->title }}" loading="lazy"
                                         class="h-full w-full object-cover transition-transform duration-500
                                                ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-[1.07]">
                                @else
                                    <div class="flex h-full items-center justify-center text-ink-300">
                                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5" />
                                        </svg>
                                    </div>
                                @endif

                                {{-- Warm sweep from the bottom on hover — the one flourish. --}}
                                <span aria-hidden="true"
                                      class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2
                                             bg-gradient-to-t from-ember-900/50 to-transparent
                                             opacity-0 transition-opacity duration-300 group-hover:opacity-100"></span>
                            </a>

                            <div class="flex flex-1 flex-col p-5">
                                @if ($article->articleCategory)
                                    <span class="text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ember-600">
                                        {{ $article->articleCategory->name }}
                                    </span>
                                @endif

                                <h2 class="mt-2 text-base font-semibold leading-snug text-ink-900">
                                    <a href="/journal/{{ $article->slug }}"
                                       class="line-clamp-2 transition-colors duration-200 hover:text-marine-700
                                              focus:outline-none focus-visible:underline">
                                        {{ $article->title }}
                                    </a>
                                </h2>

                                @if ($article->summary)
                                    <p class="mt-2 line-clamp-2 text-[0.8rem] leading-relaxed text-ink-500">
                                        {{ $article->summary }}
                                    </p>
                                @endif

                                <div class="mt-auto flex items-center gap-2 pt-4 text-[0.7rem] text-ink-400">
                                    @if ($article->author)
                                        <span>{{ $article->author->getFilamentName() }}</span>
                                        <span aria-hidden="true">·</span>
                                    @endif
                                    <time datetime="{{ $article->published_at?->toDateString() }}">
                                        {{ $article->published_at?->format('j M Y') }}
                                    </time>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="rounded-card border border-dashed border-ink-200 px-6 py-20 text-center">
                    <p class="text-sm font-medium text-ink-600">Nothing here yet.</p>
                    <p class="mt-1 text-xs text-ink-400">Try another category, or clear the filters.</p>
                </div>
            @endif
        </div>

        <div class="mt-10">{{ $this->articles->links() }}</div>

        {{-- ── Tags ───────────────────────────────────────────────────── --}}
        @if ($this->tags->isNotEmpty())
            <div class="mt-14 border-t border-ink-200 pt-8">
                <p class="text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-ink-400">Browse by tag</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->tags as $tag)
                        <button
                            type="button"
                            wire:key="tag-{{ $tag->id }}"
                            wire:click="$set('tag', '{{ $tag->slug }}')"
                            class="rounded-full px-3 py-1.5 text-xs transition-all duration-200 hover:-translate-y-0.5
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-ember-500/25
                                   {{ $this->tag === $tag->slug
                                        ? 'bg-ember-500 font-medium text-white shadow-md shadow-ember-500/25'
                                        : 'bg-white text-ink-500 ring-1 ring-ink-200 hover:ring-ember-400 hover:text-ember-700' }}"
                        >
                            #{{ $tag->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
