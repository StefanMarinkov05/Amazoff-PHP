@php
    $lead = $this->articles->first();
    $rest = $this->articles->slice(1);
    $filtered = $this->categoryId !== null || $this->tag !== null;
    $showLead = $lead && ! $filtered && $this->articles->currentPage() === 1;
    $listed = $showLead ? $rest : $this->articles;
    $offset = ($this->articles->currentPage() - 1) * $this->articles->perPage();
@endphp

<div class="bg-ink-50">

    {{-- ── Masthead ───────────────────────────────────────────────────── --}}
    <header class="border-b-2 border-ink-900 bg-ink-50">
        <div class="mx-auto max-w-6xl px-4 pt-10 pb-6">
            <div class="flex items-baseline justify-between gap-4 border-b border-ink-300 pb-3">
                <span class="text-[0.65rem] font-semibold uppercase tracking-[0.3em] text-ember-600">
                    Field notes
                </span>
                <span class="hidden text-[0.65rem] uppercase tracking-[0.25em] text-ink-400 sm:block">
                    {{ $this->articles->total() }} {{ Str::plural('piece', $this->articles->total()) }}
                </span>
            </div>

            <h1 class="animate-rise-in mt-6 font-display text-[3.5rem] leading-[0.85] tracking-tight text-ink-950 sm:text-[7rem]">
                The <em class="italic text-marine-700">Journal</em>
            </h1>

            <p class="animate-rise-in mt-6 max-w-md text-sm leading-relaxed text-ink-500" style="animation-delay: 120ms">
                Buying guides, workshop notes and the occasional strong opinion — written by the
                people who choose what goes in the catalogue.
            </p>
        </div>
    </header>

    <div class="mx-auto max-w-6xl px-4">

        {{-- ── Filter rail ────────────────────────────────────────────── --}}
        <div class="sticky top-16 z-30 -mx-4 border-b border-ink-200 bg-ink-50/90 px-4 py-3 backdrop-blur-md">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                <button
                    type="button"
                    wire:click="$set('categoryId', null)"
                    class="uppercase tracking-[0.15em] transition-colors duration-200
                           {{ $this->categoryId === null
                                ? 'font-semibold text-ink-950 underline decoration-ember-500 decoration-2 underline-offset-[6px]'
                                : 'text-ink-400 hover:text-ink-900' }}"
                >All</button>

                @foreach ($this->categories as $category)
                    <button
                        type="button"
                        wire:key="cat-{{ $category->id }}"
                        wire:click="$set('categoryId', {{ $category->id }})"
                        class="uppercase tracking-[0.15em] transition-colors duration-200
                               {{ $this->categoryId === $category->id
                                    ? 'font-semibold text-ink-950 underline decoration-ember-500 decoration-2 underline-offset-[6px]'
                                    : 'text-ink-400 hover:text-ink-900' }}"
                    >{{ $category->name }}</button>
                @endforeach

                @if ($this->tag !== null)
                    <button
                        type="button"
                        wire:click="$set('tag', null)"
                        class="ml-auto inline-flex items-center gap-1.5 bg-ember-500 px-2.5 py-1
                               font-medium text-white transition-colors duration-200 hover:bg-ember-600"
                    >
                        #{{ $this->tag }}
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                            <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>
        </div>

        <div wire:loading.class="opacity-40" class="transition-opacity duration-200">

            {{-- ── Lead story ─────────────────────────────────────────── --}}
            @if ($showLead)
                <a href="/journal/{{ $lead->slug }}" wire:key="lead-{{ $lead->id }}"
                   class="group animate-rise-in relative mt-10 grid gap-8 md:grid-cols-12">

                    <x-journal.cover :article="$lead" class="aspect-[4/3] md:col-span-7 md:aspect-[16/11]" />

                    {{-- Overlaps the art at desktop width — the grid-break that
                         stops this reading as another two-column card.
                         `relative` is load-bearing: the cover is positioned for
                         its gradient layers, and a positioned sibling paints
                         above a static one whatever the DOM order, so without
                         this the art covers the first inch of every line. --}}
                    <div class="relative z-10 md:col-span-5 md:-ml-20 md:self-center">
                        {{-- White, not `ink-50`: matching the page background
                             made the panel read as a hole cut out of the
                             artwork rather than a card resting on it. The
                             hairline border does the same job the rules
                             elsewhere on the page do — no shadow, which would
                             soften an otherwise hard-edged layout. --}}
                        <div class="border-ink-200 bg-white md:border md:p-8">
                            @if ($lead->articleCategory)
                                <span class="text-[0.65rem] font-semibold uppercase tracking-[0.25em] text-ember-600">
                                    {{ $lead->articleCategory->name }}
                                </span>
                            @endif

                            <h2 class="mt-3 font-display text-3xl leading-[1.05] text-ink-950 sm:text-[2.75rem]">
                                <span class="underline-sweep">{{ $lead->title }}</span>
                            </h2>

                            @if ($lead->summary)
                                <p class="mt-4 max-w-sm text-sm leading-relaxed text-ink-600">{{ $lead->summary }}</p>
                            @endif

                            <div class="mt-5 flex items-center gap-2 text-[0.7rem] uppercase tracking-[0.15em] text-ink-400">
                                @if ($lead->author)
                                    <span>{{ $lead->author->getFilamentName() }}</span>
                                    <span aria-hidden="true">—</span>
                                @endif
                                <time datetime="{{ $lead->published_at?->toDateString() }}">
                                    {{ $lead->published_at?->format('j M Y') }}
                                </time>
                            </div>
                        </div>
                    </div>
                </a>
            @endif

            {{-- ── Index ──────────────────────────────────────────────── --}}
            @if ($listed->count() > 0)
                <ol class="mt-16 border-t border-ink-300">
                    @foreach ($listed as $i => $article)
                        <li wire:key="article-{{ $article->id }}"
                            style="animation-delay: {{ min($i * 55, 380) }}ms"
                            class="animate-rise-in border-b border-ink-200">
                            <a href="/journal/{{ $article->slug }}"
                               class="group grid grid-cols-12 items-center gap-4 py-5 transition-colors duration-300
                                      hover:bg-ink-950 sm:gap-6">

                                <span class="col-span-2 pl-1 font-display text-lg text-ink-300 transition-colors
                                             duration-300 group-hover:text-ember-400 sm:col-span-1 sm:text-2xl">
                                    {{ str_pad((string) ($offset + $i + ($showLead ? 2 : 1)), 2, '0', STR_PAD_LEFT) }}
                                </span>

                                <div class="col-span-10 sm:col-span-6">
                                    @if ($article->articleCategory)
                                        <span class="text-[0.6rem] font-semibold uppercase tracking-[0.22em] text-ember-600
                                                     transition-colors duration-300 group-hover:text-ember-400">
                                            {{ $article->articleCategory->name }}
                                        </span>
                                    @endif
                                    <h2 class="mt-1 font-display text-xl leading-tight text-ink-950 transition-colors
                                               duration-300 group-hover:text-white sm:text-2xl">
                                        {{ $article->title }}
                                    </h2>
                                </div>

                                <p class="col-span-10 col-start-3 line-clamp-2 text-xs leading-relaxed text-ink-500
                                          transition-colors duration-300 group-hover:text-ink-300
                                          sm:col-span-3 sm:col-start-auto sm:line-clamp-3">
                                    {{ $article->summary }}
                                </p>

                                <span class="col-span-2 hidden justify-self-end pr-2 transition-transform duration-300
                                             group-hover:translate-x-1.5 sm:col-span-2 sm:block">
                                    <svg class="h-5 w-5 text-ink-300 transition-colors duration-300 group-hover:text-ember-400"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                    </svg>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @else
                <div class="mt-16 border-y border-ink-300 py-24 text-center">
                    <p class="font-display text-2xl text-ink-900">Nothing filed here yet.</p>
                    <p class="mt-2 text-xs uppercase tracking-[0.2em] text-ink-400">Try another section</p>
                </div>
            @endif
        </div>

        <div class="mt-10">{{ $this->articles->links() }}</div>

        {{-- ── Tags ───────────────────────────────────────────────────── --}}
        @if ($this->tags->isNotEmpty())
            <div class="mt-16 border-t-2 border-ink-900 pt-6 pb-16">
                <p class="font-display text-xl text-ink-950">Also filed under</p>

                <div class="mt-4 flex flex-wrap gap-x-1.5 gap-y-2">
                    @foreach ($this->tags as $tag)
                        <button
                            type="button"
                            wire:key="tag-{{ $tag->id }}"
                            wire:click="$set('tag', '{{ $tag->slug }}')"
                            class="border px-2.5 py-1 text-xs transition-all duration-200
                                   {{ $this->tag === $tag->slug
                                        ? 'border-ember-500 bg-ember-500 font-medium text-white'
                                        : 'border-ink-300 text-ink-500 hover:border-ink-950 hover:bg-ink-950 hover:text-white' }}"
                        >{{ $tag->name }}</button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
