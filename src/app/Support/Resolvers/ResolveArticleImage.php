<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\Article;
use Illuminate\Support\Facades\Storage;

/**
 * Whether an article has a real cover photo on disk, and its URL if so.
 *
 * Same shape as `ResolveVariationImage`, with one deliberate difference:
 * this returns `null` rather than a placeholder path when there is no real
 * file. `components/journal/cover.blade.php` already has its own fallback
 * — the generated gradient cover — so there is no reason to introduce a
 * second "default" asset competing with it.
 *
 * A function, not an Action: it writes nothing.
 */
final class ResolveArticleImage
{
    /**
     * The servable URL for `$article->main_image_path`, or `null` if that
     * path is empty or does not exist on disk — true for every seeded demo
     * article until `demo:fetch-article-images` is run, and for any article
     * an editor has not yet uploaded a photo for.
     */
    public static function urlOrNull(Article $article): ?string
    {
        $path = $article->main_image_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        // Seed covers and uploaded covers live on different disks; the path
        // prefix decides which, so this stays one `exists()` call. ADR-0025.
        $disk = $article->imageDisk();

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return Storage::disk($disk)->url($path);
    }
}
