@php
    $article = $this->article;
    // ~200 wpm over the sanitized body. Rounded up so a short read never says 0.
    $minutes = max(1, (int) ceil(str_word_count(strip_tags((string) $article->safe_content)) / 200));
@endphp

<div class="bg-ink-50">

    {{-- Reading progress. Alpine writes the percentage on scroll; the bar is
         one element with a transform, so no layout is recalculated per frame. --}}
    <div
        x-data="{
            progress: 0,
            track() {
                const h = document.documentElement.scrollHeight - window.innerHeight;
                this.progress = h > 0 ? Math.min(100, (window.scrollY / h) * 100) : 0;
            },
        }"
        x-init="track()"
        @scroll.window.passive="track()"
        @resize.window.passive="track()"
        class="fixed inset-x-0 top-0 z-50 h-1 bg-transparent"
        aria-hidden="true"
    >
        <div class="h-full origin-left bg-gradient-to-r from-marine-600 to-ember-500 transition-transform duration-150 ease-out"
             :style="`transform: scaleX(${progress / 100})`"></div>
    </div>

    {{-- ── Hero ───────────────────────────────────────────────────────── --}}
    <header class="relative overflow-hidden bg-ink-950">
        @if ($article->main_image_path)
            <img src="{{ Storage::url($article->main_image_path) }}" alt=""
                 class="animate-drift absolute inset-0 h-full w-full object-cover opacity-35">
        @endif

        <div aria-hidden="true"
             class="absolute inset-0 bg-gradient-to-br from-marine-900/70 via-ink-950/85 to-ember-900/50"></div>

        <div class="relative mx-auto max-w-3xl px-4 py-16 sm:py-24">
            <nav aria-label="Breadcrumb" class="animate-rise-in mb-6 text-xs text-ink-400">
                <ol class="flex flex-wrap items-center gap-1.5">
                    <li><a href="/journal" class="transition-colors hover:text-ember-400">Journal</a></li>
                    @if ($article->articleCategory)
                        <li aria-hidden="true">/</li>
                        <li>
                            <a href="/journal?categoryId={{ $article->articleCategory->id }}"
                               class="transition-colors hover:text-ember-400">
                                {{ $article->articleCategory->name }}
                            </a>
                        </li>
                    @endif
                </ol>
            </nav>

            @if ($article->articleCategory)
                <a href="/journal?categoryId={{ $article->articleCategory->id }}"
                   class="animate-rise-in inline-block rounded-full bg-ember-500/15 px-3 py-1
                          text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-ember-400
                          ring-1 ring-ember-500/30 transition-colors duration-200 hover:bg-ember-500/25">
                    {{ $article->articleCategory->name }}
                </a>
            @endif

            <h1 class="animate-rise-in mt-5 text-3xl font-semibold leading-[1.1] tracking-tight text-white sm:text-5xl"
                style="animation-delay: 80ms">
                {{ $article->title }}
            </h1>

            @if ($article->summary)
                <p class="animate-rise-in mt-5 max-w-2xl text-base leading-relaxed text-ink-300"
                   style="animation-delay: 150ms">
                    {{ $article->summary }}
                </p>
            @endif

            <div class="animate-rise-in mt-8 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-ink-400"
                 style="animation-delay: 220ms">
                @if ($article->author)
                    <span class="flex h-8 w-8 items-center justify-center rounded-full
                                 bg-gradient-to-br from-marine-500 to-ember-500 text-[0.7rem] font-bold text-white">
                        {{ mb_substr($article->author->first_name ?? '?', 0, 1) }}{{ mb_substr($article->author->last_name ?? '', 0, 1) }}
                    </span>
                    <span class="font-medium text-ink-200">{{ $article->author->getFilamentName() }}</span>
                    <span aria-hidden="true">·</span>
                @endif

                <time datetime="{{ $article->published_at?->toDateString() }}">
                    {{ $article->published_at?->format('j F Y') }}
                </time>
                <span aria-hidden="true">·</span>
                <span>{{ $minutes }} min read</span>
            </div>
        </div>
    </header>

    {{-- ── Body ───────────────────────────────────────────────────────── --}}
    <div class="mx-auto max-w-3xl px-4 py-12 sm:py-16">
        {{-- `safe_content` is an HtmlString, so `{{ }}` renders the HTML without
             this template ever writing `{!! !!}`. ADR-0015.
             `whitespace-pre-line` matters while fixture articles are still plain
             text with newlines rather than markup. --}}
        <div class="article-body whitespace-pre-line text-[0.95rem]">
            {{ $article->safe_content }}
        </div>

        @if ($article->tags->isNotEmpty())
            <div class="mt-12 flex flex-wrap items-center gap-2 border-t border-ink-200 pt-8">
                <span class="mr-1 text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-ink-400">Tagged</span>
                @foreach ($article->tags as $tag)
                    <a href="/journal?tag={{ $tag->slug }}" wire:key="tag-{{ $tag->id }}"
                       class="rounded-full bg-white px-3 py-1.5 text-xs text-ink-600 ring-1 ring-ink-200
                              transition-all duration-200 hover:-translate-y-0.5 hover:text-ember-700 hover:ring-ember-400">
                        #{{ $tag->name }}
                    </a>
                @endforeach
            </div>
        @endif

        <a href="/journal"
           class="group mt-10 inline-flex items-center gap-2 text-sm font-semibold text-marine-700
                  transition-colors duration-200 hover:text-ember-600">
            <svg class="h-4 w-4 transition-transform duration-300 group-hover:-translate-x-1"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            All articles
        </a>
    </div>

    {{-- ── Related ────────────────────────────────────────────────────── --}}
    @if ($this->related->isNotEmpty())
        <section class="border-t border-ink-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-14">
                <h2 class="text-lg font-semibold tracking-tight text-ink-900">Keep reading</h2>

                <div class="mt-6 grid gap-6 sm:grid-cols-3">
                    @foreach ($this->related as $other)
                        <a href="/journal/{{ $other->slug }}" wire:key="related-{{ $other->id }}"
                           class="group flex flex-col overflow-hidden rounded-card border border-ink-200
                                  transition-all duration-300 hover:-translate-y-1.5
                                  hover:border-marine-600/40 hover:shadow-xl hover:shadow-ink-900/10">
                            <div class="aspect-[16/10] overflow-hidden bg-ink-100">
                                @if ($other->main_image_path)
                                    <img src="{{ Storage::url($other->main_image_path) }}" alt="{{ $other->title }}"
                                         loading="lazy"
                                         class="h-full w-full object-cover transition-transform duration-500
                                                ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-[1.07]">
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-4">
                                <h3 class="line-clamp-2 text-sm font-semibold leading-snug text-ink-900
                                           transition-colors duration-200 group-hover:text-marine-700">
                                    {{ $other->title }}
                                </h3>
                                <time class="mt-auto pt-3 text-[0.7rem] text-ink-400"
                                      datetime="{{ $other->published_at?->toDateString() }}">
                                    {{ $other->published_at?->format('j M Y') }}
                                </time>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
