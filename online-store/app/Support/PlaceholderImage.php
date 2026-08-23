<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders a deterministic SVG stand-in for a demo product photograph.
 *
 * ADR-0003: "Product images are generated, not collected. No image binaries
 * enter git." Scraping a real shop would republish someone else's
 * photographs; an external placeholder service would make seeding require
 * network access. An SVG drawn from the product's own name needs neither and
 * is byte-identical on every run, which keeps seeding deterministic.
 *
 * A grid of twelve initials-in-a-box reads as a wireframe rather than a shop,
 * so each product gets a silhouette chosen from its category — enough shape
 * for the eye to parse the grid as merchandise, without pretending to be a
 * photograph.
 */
final class PlaceholderImage
{
    private const DISK = 'public';

    /**
     * Category keyword → silhouette. First match wins, so order matters:
     * "coffee" must beat the generic fallback, not the other way round.
     */
    private const SHAPES = [
        'headphone' => 'headphones',
        'speaker' => 'speaker',
        'turntable' => 'turntable',
        'keyboard' => 'keyboard',
        'mice' => 'mouse',
        'mouse' => 'mouse',
        'monitor' => 'monitor',
        'cookware' => 'pan',
        'coffee' => 'kettle',
        'tea' => 'kettle',
        'lighting' => 'lamp',
        'backpack' => 'backpack',
        'camping' => 'tent',
        'navigation' => 'tool',
    ];

    public static function render(string $name, ?string $category = null, string $directory = 'demo/products'): string
    {
        $slug = Str::slug($name);
        $path = "{$directory}/{$slug}.svg";

        Storage::disk(self::DISK)->put($path, self::svg($name, $category));

        return $path;
    }

    private static function svg(string $name, ?string $category): string
    {
        // Stable hue per product: same item, same colour, every re-seed.
        $hue = crc32($name) % 360;
        $shape = self::shapeFor($category);

        $backdrop = "hsl({$hue}, 24%, 95%)";
        $backdropDeep = "hsl({$hue}, 26%, 88%)";
        $body = "hsl({$hue}, 30%, 42%)";
        $bodyLight = "hsl({$hue}, 26%, 58%)";
        $accent = "hsl({$hue}, 45%, 32%)";

        $art = self::art($shape, $body, $bodyLight, $accent);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800" width="800" height="800" role="img" aria-label="{$name}">
            <defs>
                <radialGradient id="bg" cx="50%" cy="38%" r="72%">
                    <stop offset="0%" stop-color="{$backdrop}"/>
                    <stop offset="100%" stop-color="{$backdropDeep}"/>
                </radialGradient>
                <linearGradient id="sheen" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#fff" stop-opacity="0.35"/>
                    <stop offset="100%" stop-color="#fff" stop-opacity="0"/>
                </linearGradient>
                <filter id="soft" x="-25%" y="-25%" width="150%" height="150%">
                    <feGaussianBlur stdDeviation="18"/>
                </filter>
            </defs>

            <rect width="800" height="800" fill="url(#bg)"/>

            <!-- Contact shadow: what makes the silhouette sit on a surface
                 rather than float in a coloured void. -->
            <ellipse cx="400" cy="612" rx="196" ry="26" fill="{$accent}" opacity="0.16" filter="url(#soft)"/>

            <g transform="translate(400 396)">
                {$art}
            </g>

            <rect width="800" height="330" fill="url(#sheen)"/>
        </svg>
        SVG;
    }

    private static function shapeFor(?string $category): string
    {
        $haystack = Str::lower((string) $category);

        foreach (self::SHAPES as $keyword => $shape) {
            if ($haystack !== '' && str_contains($haystack, $keyword)) {
                return $shape;
            }
        }

        return 'box';
    }

    /** Silhouettes are drawn around a 0,0 origin so the caller can position them. */
    private static function art(string $shape, string $body, string $light, string $accent): string
    {
        return match ($shape) {
            'headphones' => <<<ART
                <path d="M-150 40 V-20 a150 150 0 0 1 300 0 V40" fill="none" stroke="{$body}" stroke-width="30" stroke-linecap="round"/>
                <rect x="-186" y="20" width="74" height="130" rx="34" fill="{$accent}"/>
                <rect x="112" y="20" width="74" height="130" rx="34" fill="{$accent}"/>
                <rect x="-172" y="42" width="46" height="86" rx="22" fill="{$light}" opacity="0.55"/>
                ART,

            'speaker' => <<<ART
                <rect x="-104" y="-150" width="208" height="300" rx="34" fill="{$body}"/>
                <circle cx="0" cy="-52" r="52" fill="{$accent}"/>
                <circle cx="0" cy="-52" r="22" fill="{$light}" opacity="0.6"/>
                <circle cx="0" cy="78" r="34" fill="{$accent}"/>
                <rect x="-62" y="132" width="124" height="10" rx="5" fill="{$light}" opacity="0.5"/>
                ART,

            'turntable' => <<<ART
                <rect x="-190" y="-70" width="380" height="200" rx="20" fill="{$body}"/>
                <circle cx="-42" cy="30" r="118" fill="{$accent}"/>
                <circle cx="-42" cy="30" r="42" fill="{$light}" opacity="0.5"/>
                <circle cx="-42" cy="30" r="10" fill="{$body}"/>
                <rect x="118" y="-46" width="20" height="150" rx="10" fill="{$light}" transform="rotate(16 128 30)"/>
                ART,

            'keyboard' => <<<ART
                <rect x="-220" y="-70" width="440" height="180" rx="22" fill="{$body}"/>
                <g fill="{$light}" opacity="0.62">
                    <rect x="-196" y="-44" width="44" height="40" rx="8"/><rect x="-142" y="-44" width="44" height="40" rx="8"/>
                    <rect x="-88" y="-44" width="44" height="40" rx="8"/><rect x="-34" y="-44" width="44" height="40" rx="8"/>
                    <rect x="20" y="-44" width="44" height="40" rx="8"/><rect x="74" y="-44" width="44" height="40" rx="8"/>
                    <rect x="128" y="-44" width="68" height="40" rx="8"/>
                    <rect x="-196" y="8" width="60" height="40" rx="8"/><rect x="-126" y="8" width="44" height="40" rx="8"/>
                    <rect x="-72" y="8" width="44" height="40" rx="8"/><rect x="-18" y="8" width="44" height="40" rx="8"/>
                    <rect x="36" y="8" width="44" height="40" rx="8"/><rect x="90" y="8" width="106" height="40" rx="8"/>
                </g>
                ART,

            'mouse' => <<<ART
                <path d="M0-170c72 0 118 62 118 140v52c0 78-46 128-118 128S-118 100-118 22v-52C-118-108-72-170 0-170Z" fill="{$body}"/>
                <path d="M0-170c72 0 118 62 118 140v18H0Z" fill="{$light}" opacity="0.45"/>
                <rect x="-11" y="-104" width="22" height="58" rx="11" fill="{$accent}"/>
                ART,

            'monitor' => <<<ART
                <rect x="-230" y="-160" width="460" height="272" rx="18" fill="{$body}"/>
                <rect x="-206" y="-136" width="412" height="212" rx="8" fill="{$light}" opacity="0.5"/>
                <rect x="-34" y="112" width="68" height="58" fill="{$accent}"/>
                <rect x="-118" y="164" width="236" height="22" rx="11" fill="{$accent}"/>
                ART,

            'pan' => <<<ART
                <path d="M-160-40h320v58a160 160 0 0 1-320 0Z" fill="{$body}"/>
                <ellipse cx="0" cy="-40" rx="160" ry="42" fill="{$light}" opacity="0.55"/>
                <rect x="150" y="-72" width="200" height="30" rx="15" fill="{$accent}" transform="rotate(-14 150 -57)"/>
                ART,

            'kettle' => <<<ART
                <path d="M-118 22a118 118 0 0 1 236 0v40a58 58 0 0 1-58 58h-120a58 58 0 0 1-58-58Z" fill="{$body}"/>
                <path d="M-112-10c40-92 184-92 224 0Z" fill="{$light}" opacity="0.4"/>
                <path d="M110-34c76-30 120 22 96 78" fill="none" stroke="{$accent}" stroke-width="22" stroke-linecap="round"/>
                <path d="M-108-30c-84-16-124 30-108 84" fill="none" stroke="{$accent}" stroke-width="22" stroke-linecap="round"/>
                <rect x="-26" y="-136" width="52" height="34" rx="14" fill="{$accent}"/>
                ART,

            'lamp' => <<<ART
                <path d="M-96-40 0-158l96 118Z" fill="{$body}"/>
                <ellipse cx="0" cy="-40" rx="96" ry="20" fill="{$light}" opacity="0.6"/>
                <rect x="-7" y="-40" width="14" height="180" rx="7" fill="{$accent}"/>
                <ellipse cx="0" cy="146" rx="106" ry="26" fill="{$accent}"/>
                ART,

            'backpack' => <<<ART
                <path d="M-124-70a124 124 0 0 1 248 0v182a38 38 0 0 1-38 38h-172a38 38 0 0 1-38-38Z" fill="{$body}"/>
                <path d="M-70-96a70 70 0 0 1 140 0" fill="none" stroke="{$accent}" stroke-width="24"/>
                <rect x="-78" y="16" width="156" height="86" rx="18" fill="{$light}" opacity="0.5"/>
                <rect x="-24" y="46" width="48" height="12" rx="6" fill="{$accent}"/>
                ART,

            'tent' => <<<ART
                <path d="M0-150 190 130H-190Z" fill="{$body}"/>
                <path d="M0-150 60 130H-60Z" fill="{$accent}" opacity="0.85"/>
                <path d="M0-56 44 130H-44Z" fill="{$light}" opacity="0.55"/>
                <rect x="-196" y="126" width="392" height="14" rx="7" fill="{$accent}"/>
                ART,

            'tool' => <<<ART
                <rect x="-24" y="-160" width="48" height="200" rx="12" fill="{$light}"/>
                <rect x="-40" y="30" width="80" height="130" rx="26" fill="{$body}"/>
                <rect x="-40" y="66" width="80" height="12" fill="{$accent}" opacity="0.7"/>
                <rect x="-40" y="98" width="80" height="12" fill="{$accent}" opacity="0.7"/>
                ART,

            default => <<<ART
                <rect x="-140" y="-140" width="280" height="280" rx="28" fill="{$body}"/>
                <rect x="-140" y="-140" width="280" height="140" rx="28" fill="{$light}" opacity="0.35"/>
                <rect x="-56" y="-14" width="112" height="14" rx="7" fill="{$accent}"/>
                ART,
        };
    }
}
