{{-- Shared shell for the plain informational pages (delivery, payment,
     terms, privacy, cookies, FAQ).

     These six are the same shape: a heading, a standfirst, and body copy with
     no state and no query. Extracting the shell keeps implementation standard
     #8 ("no oversized Blade files; repeated markup extracted into
     components") true as they are added, rather than six near-identical
     files diverging at the edges.

     `/about` deliberately does not use this — it carries its own hero and
     layout, and folding it in would mean parameterising this component for
     one caller. --}}
@props(['title', 'standfirst' => null, 'updated' => null])

<x-layouts.app :title="$title">
    <div class="bg-ink-50">
        <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:py-20">

            <h1 class="text-3xl font-semibold tracking-tight text-ink-900">{{ $title }}</h1>

            @if ($standfirst)
                <p class="mt-3 text-base leading-relaxed text-ink-600">{{ $standfirst }}</p>
            @endif

            @if ($updated)
                <p class="mt-2 text-xs uppercase tracking-[0.15em] text-ink-400">
                    Last updated {{ $updated }}
                </p>
            @endif

            {{-- Typography is set here rather than per page so the six stay
                 consistent: headings, paragraphs, lists and links all pick up
                 the same treatment from one place. --}}
            <div class="mt-10 space-y-6 text-sm leading-relaxed text-ink-700
                        [&_h2]:mt-10 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-ink-900
                        [&_h3]:mt-6 [&_h3]:text-sm [&_h3]:font-semibold [&_h3]:text-ink-900
                        [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5
                        [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:pl-5
                        [&_a]:font-medium [&_a]:text-marine-700 [&_a]:underline-offset-4 hover:[&_a]:underline">
                {{ $slot }}
            </div>

            <a href="/catalogue" wire:navigate
               class="mt-12 inline-block rounded-control border border-ink-300 bg-white px-4 py-2.5 text-sm
                      font-medium text-ink-800 hover:bg-ink-50 focus:outline-none
                      focus-visible:ring-4 focus-visible:ring-marine-600/20">
                Back to the catalogue
            </a>
        </div>
    </div>
</x-layouts.app>
