<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders a deterministic SVG placeholder for a demo product image.
 *
 * ADR-0003: "Product images are generated, not collected. No image binaries
 * enter git." Scraping a real shop would put someone else's photographs on a
 * public URL; an external placeholder service would make `demo:seed` require
 * network access. An SVG rendered from the product's own name needs neither,
 * and is byte-identical on every run, which keeps seeding deterministic.
 *
 * The hue is derived from the name so a product keeps its colour across
 * re-seeds, and two products never collide into an indistinguishable grid.
 */
final class PlaceholderImage
{
    private const DISK = 'public';

    /**
     * @return string the path relative to the public disk, for `products.path`
     */
    public static function render(string $name, string $directory = 'demo/products'): string
    {
        $slug = Str::slug($name);
        $path = "{$directory}/{$slug}.svg";

        if (! Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->put($path, self::svg($name));
        }

        return $path;
    }

    private static function svg(string $name): string
    {
        // Stable hue per name: same product, same colour, every run.
        $hue = crc32($name) % 360;
        $initials = Str::of($name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');

        $label = htmlspecialchars(Str::limit($name, 28), ENT_QUOTES | ENT_XML1);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="800" height="600" role="img" aria-label="{$label}">
            <defs>
                <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="hsl({$hue}, 32%, 92%)"/>
                    <stop offset="100%" stop-color="hsl({$hue}, 28%, 80%)"/>
                </linearGradient>
            </defs>
            <rect width="800" height="600" fill="url(#g)"/>
            <circle cx="400" cy="270" r="120" fill="hsl({$hue}, 30%, 97%)" opacity="0.65"/>
            <text x="400" y="300" text-anchor="middle"
                  font-family="Instrument Sans, system-ui, sans-serif" font-size="110" font-weight="600"
                  fill="hsl({$hue}, 40%, 34%)">{$initials}</text>
            <text x="400" y="470" text-anchor="middle"
                  font-family="Instrument Sans, system-ui, sans-serif" font-size="30"
                  fill="hsl({$hue}, 25%, 42%)">{$label}</text>
        </svg>
        SVG;
    }
}
