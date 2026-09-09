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
@props(['title', 'standfirst' => null, 'updated' => null, 'draft' => false])

<x-layouts.app :title="$title">
    <div class="bg-ink-50">
        <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:py-20">

            @if ($draft)
                {{-- ADR-0019: the privacy notice and T&Cs read like real
                     documents and are explicitly not certified. This banner
                     stays until counsel has reviewed the text against the
                     operating company's actual details and Bulgarian law. --}}
                <div role="note"
                     class="mb-8 rounded-card border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong>Draft — not legal advice yet.</strong>
                    This document is written to the correct shape and content
                    but has not been reviewed by a lawyer or checked against the
                    operating company's registration details. It must be before
                    this shop takes real orders. Placeholder details are marked
                    <span class="rounded bg-amber-100 px-1 font-mono text-xs">[like this]</span>.
                </div>
            @endif

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
                        [&_a]:font-medium [&_a]:text-marine-700 [&_a]:underline-offset-4 hover:[&_a]:underline
                        [&_table]:mt-4 [&_table]:block [&_table]:overflow-x-auto [&_table]:text-left
                        [&_th]:border-b [&_th]:border-ink-300 [&_th]:py-2 [&_th]:pr-4 [&_th]:align-top [&_th]:font-semibold [&_th]:text-ink-900
                        [&_td]:border-b [&_td]:border-ink-200 [&_td]:py-2 [&_td]:pr-4 [&_td]:align-top">
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
