@props(['article', 'index' => null])

@php
    /*
     * Generated cover art. Every article's `main_image_path` points at a file
     * that does not exist — `demo:fetch-images` covers `product_images` only —
     * so rendering the path gives 23 broken images. Drawing the cover from the
     * slug instead is the same move `App\Support\PlaceholderImage` makes for
     * products: deterministic, so an article keeps its identity across
     * re-seeds, and it cannot 404.
     *
     * Two hues a fixed distance apart keep every cover inside the blue/orange
     * family while still being individual.
     */
    $seed = crc32($article->slug);
    $hueA = $seed % 360;
    $hueB = ($hueA + 145) % 360;
    $rot = ($seed >> 8) % 90;
    $label = $index !== null ? str_pad((string) $index, 2, '0', STR_PAD_LEFT) : null;
@endphp

<div {{ $attributes->merge(['class' => 'relative overflow-hidden bg-ink-900']) }}>
    {{-- Layered conic + radial washes. Conic gives the hard colour-field edge
         a risograph print has; the radials soften two corners so it does not
         read as a pie chart. --}}
    <div
        aria-hidden="true"
        class="absolute inset-0 transition-transform duration-[1.2s] ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-110"
        style="
            background:
                radial-gradient(circle at 78% 18%, oklch(0.72 0.19 {{ $hueB }} / 0.85) 0%, transparent 55%),
                radial-gradient(circle at 12% 88%, oklch(0.55 0.17 {{ $hueA }} / 0.9) 0%, transparent 60%),
                conic-gradient(from {{ $rot }}deg at 40% 45%,
                    oklch(0.42 0.16 {{ $hueA }}) 0deg,
                    oklch(0.66 0.2 {{ $hueB }}) 130deg,
                    oklch(0.3 0.12 {{ $hueA }}) 240deg,
                    oklch(0.42 0.16 {{ $hueA }}) 360deg);
        "
    ></div>

    {{-- Grain. An inline SVG turbulence filter, not an image file — it is a
         few hundred bytes and needs nothing on disk. --}}
    <div
        aria-hidden="true"
        class="absolute inset-0 opacity-[0.18] mix-blend-overlay"
        style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)'/%3E%3C/svg%3E&quot;);"
    ></div>

    {{-- Halftone dots, thinning toward the top right. --}}
    <div
        aria-hidden="true"
        class="absolute inset-0 opacity-25"
        style="
            background-image: radial-gradient(circle, rgba(255,255,255,0.55) 1px, transparent 1px);
            background-size: 9px 9px;
            mask-image: linear-gradient(115deg, black 5%, transparent 65%);
        "
    ></div>

    @if ($label)
        <span
            aria-hidden="true"
            class="absolute -bottom-5 -left-1 select-none font-display text-[7rem] leading-none text-white/20
                   transition-all duration-500 group-hover:text-white/35 sm:-bottom-7 sm:text-[9rem]"
        >{{ $label }}</span>
    @endif

    {{ $slot ?? '' }}
</div>
