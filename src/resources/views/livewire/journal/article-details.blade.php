@php
    $article = $this->article;
    $minutes = max(1, (int) ceil(str_word_count(strip_tags((string) $article->safe_content)) / 200));
@endphp

<div class="bg-ink-50">

    {{-- Reading progress. Alpine writes a percentage; the bar scales on the
         GPU rather than relayouting a width on every scroll frame. --}}
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
        class="fixed inset-x-0 top-0 z-50 h-[3px]"
        aria-hidden="true"
    >
        <div class="h-full origin-left bg-ember-500 transition-transform duration-150 ease-out"
             :style="`transform: scaleX(${progress / 100})`"></div>
    </div>

    {{-- ── Title block ────────────────────────────────────────────────── --}}
    <header class="border-b-2 border-ink-900">
        <div class="mx-auto max-w-6xl px-4 pt-10 pb-8">
            <nav aria-label="Breadcrumb" class="mb-8 text-[0.65rem] uppercase tracking-[0.2em] text-ink-400">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="/journal" class="-my-1 inline-block py-1 transition-colors hover:text-ember-600">Journal</a></li>
                    @if ($article->articleCategory)
                        <li aria-hidden="true">—</li>
                        <li>
                            <a href="/journal?categoryId={{ $article->articleCategory->id }}"
                               class="-my-1 inline-block py-1 text-ember-600 transition-colors hover:text-ink-950">
                                {{ $article->articleCategory->name }}
                            </a>
                        </li>
                    @endif
                </ol>
            </nav>

            <div class="grid gap-8 md:grid-cols-12">
                <h1 class="animate-rise-in font-display text-[2.5rem] leading-[0.95] tracking-tight text-ink-950 md:col-span-8 md:text-[4.5rem]">
                    {{ $article->title }}
                </h1>

                <div class="animate-rise-in md:col-span-4 md:pt-3" style="animation-delay: 120ms">
                    @if ($article->summary)
                        <p class="border-l-2 border-ember-500 pl-4 text-sm leading-relaxed text-ink-600">
                            {{ $article->summary }}
                        </p>
                    @endif

                    <dl class="mt-6 space-y-1.5 text-[0.7rem] uppercase tracking-[0.15em] text-ink-400">
                        @if ($article->author)
                            <div class="flex gap-3">
                                <dt class="w-16 shrink-0 text-ink-300">By</dt>
                                <dd class="text-ink-700">{{ $article->author->getFilamentName() }}</dd>
                            </div>
                        @endif
                        <div class="flex gap-3">
                            <dt class="w-16 shrink-0 text-ink-300">Filed</dt>
                            <dd class="text-ink-700">
                                <time datetime="{{ $article->published_at?->toDateString() }}">
                                    {{ $article->published_at?->format('j M Y') }}
                                </time>
                            </dd>
                        </div>
                        <div class="flex gap-3">
                            <dt class="w-16 shrink-0 text-ink-300">Read</dt>
                            <dd class="text-ink-700">{{ $minutes }} min</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </header>

    {{-- ── Cover ──────────────────────────────────────────────────────── --}}
    <div class="group mx-auto max-w-6xl px-4">
        <x-journal.cover :article="$article" class="mt-8 aspect-[21/9]" />
    </div>

    {{-- ── Body ───────────────────────────────────────────────────────── --}}
    <div class="mx-auto max-w-6xl px-4 py-14">
        <div class="grid gap-10 md:grid-cols-12">
            {{-- Drop cap. `first-letter` needs a block element, which the
                 wrapper provides; it applies to the first block inside. --}}
            <div class="article-body whitespace-pre-line text-[1.0625rem] md:col-span-8 md:col-start-3
                        first-letter:float-left first-letter:mr-3 first-letter:mt-1
                        first-letter:font-display first-letter:text-[4.5rem]
                        first-letter:leading-[0.75] first-letter:text-ember-600">
                {{ $article->safe_content }}
            </div>
        </div>

        @if ($article->tags->isNotEmpty())
            <div class="mt-14 grid md:grid-cols-12">
                <div class="flex flex-wrap items-center gap-1.5 border-t border-ink-300 pt-6 md:col-span-8 md:col-start-3">
                    <span class="mr-2 text-[0.65rem] uppercase tracking-[0.2em] text-ink-400">Tagged</span>
                    @foreach ($article->tags as $tag)
                        <a href="/journal?tag={{ $tag->slug }}" wire:key="tag-{{ $tag->id }}"
                           class="border border-ink-300 px-2.5 py-1 text-xs text-ink-500 transition-all duration-200
                                  hover:border-ink-950 hover:bg-ink-950 hover:text-white">
                            {{ $tag->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ── Related ────────────────────────────────────────────────────── --}}
    @if ($this->related->isNotEmpty())
        <section class="border-t-2 border-ink-900 bg-ink-950">
            <div class="mx-auto max-w-6xl px-4 py-14">
                <p class="font-display text-2xl text-white">Keep reading</p>

                <div class="mt-8 grid gap-px bg-ink-800 sm:grid-cols-3">
                    @foreach ($this->related as $i => $other)
                        <a href="/journal/{{ $other->slug }}" wire:key="related-{{ $other->id }}"
                           class="group flex flex-col bg-ink-950 p-6 transition-colors duration-300 hover:bg-ink-900">
                            <x-journal.cover :article="$other" :index="$i + 1" class="aspect-[3/2]" />

                            @if ($other->articleCategory)
                                <span class="mt-5 text-[0.6rem] font-semibold uppercase tracking-[0.22em] text-ember-400">
                                    {{ $other->articleCategory->name }}
                                </span>
                            @endif

                            <h3 class="mt-2 font-display text-lg leading-tight text-white transition-colors
                                       duration-300 group-hover:text-ember-400">
                                {{ $other->title }}
                            </h3>

                            <time class="mt-auto pt-4 text-[0.65rem] uppercase tracking-[0.15em] text-ink-500"
                                  datetime="{{ $other->published_at?->toDateString() }}">
                                {{ $other->published_at?->format('j M Y') }}
                            </time>
                        </a>
                    @endforeach
                </div>

                <a href="/journal"
                   class="group mt-10 inline-flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-ink-400
                          transition-colors duration-200 hover:text-ember-400">
                    <svg class="h-4 w-4 transition-transform duration-300 group-hover:-translate-x-1"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    All articles
                </a>
            </div>
        </section>
    @endif
</div>
