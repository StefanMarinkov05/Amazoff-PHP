<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Downloads a real Pexels photo for every article whose `main_image_path`
 * is empty or does not exist on disk, and writes it there.
 *
 * The article equivalent of `FetchDemoImages` — same reasoning, same
 * source, same reliability history (Pexels over Unsplash's 50/hour cap and
 * a scraper's ~40% hotlink-placeholder rate; see that command's own
 * docblock for the full account, not repeated here). Not wired into any
 * `Demo*` seeder, run once by hand after the article fixtures are loaded.
 *
 * `articles.main_image_path` was never written by `DemoArticleSeeder` or
 * `ArticleFixtureLoader` with a real value — `components/journal/cover.blade.php`
 * has always fallen back to generated art instead. This command is what
 * lets a real photo win there.
 *
 * Requires `PEXELS_API_KEY` in `.env` — a free key from pexels.com/api.
 */
final class FetchDemoArticleImages extends Command
{
    protected $signature = 'demo:fetch-article-images
        {--limit= : Maximum articles to process this run (default: all still missing)}
        {--dry-run : Report what would be fetched without writing anything}';

    protected $description = 'Download a real Pexels photo for every article still missing main_image_path on disk';

    private const API_BASE = 'https://api.pexels.com/v1';

    public function handle(): int
    {
        $apiKey = config('services.pexels.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            $this->error('PEXELS_API_KEY is not set in .env. Get a free key at pexels.com/api.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        // is_numeric, not is_string: the CLI always hands options over as
        // strings, but Artisan::call(..., ['--limit' => 1]) — how this was
        // exercised in testing, since there's no PEXELS_API_KEY in every
        // environment — passes a real int. A string-only check silently
        // treated that as "no limit" and processed every article instead of
        // the requested subset; caught by running the command that way and
        // finding it had rewritten far more rows than --limit=1 asked for.
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : PHP_INT_MAX;
        $dryRun = (bool) $this->option('dry-run');

        $articles = $this->articlesNeedingImages($limit);

        if ($articles->isEmpty()) {
            $this->info('Every article already has a real main_image_path on disk. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d article(s) still need a real image%s.',
            $articles->count(),
            $dryRun ? ' — dry run, nothing will be written' : '',
        ));

        $fetched = 0;
        $failed = 0;

        foreach ($articles as $article) {
            try {
                $this->fillArticleImage($article, $apiKey, $dryRun);
                $fetched++;
                $this->line("  {$article->slug}: image written.");
            } catch (Throwable $e) {
                $failed++;
                $this->warn("  {$article->slug}: failed — {$e->getMessage()}");
            }
        }

        $this->info("Done. {$fetched} image(s) written, {$failed} failed.");

        if (! $dryRun) {
            $remaining = $this->articlesNeedingImages(PHP_INT_MAX)->count();

            if ($remaining > 0) {
                $this->info("{$remaining} article(s) still remain — re-run this command to continue.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Articles whose `main_image_path` is empty, or points at a file
     * missing on disk — the ones this run still has to fix.
     *
     * @return EloquentCollection<int, Article>
     */
    private function articlesNeedingImages(int $limit): EloquentCollection
    {
        /** @var EloquentCollection<int, Article> $articles */
        $articles = Article::query()->with('articleCategory')->get();

        /** @var EloquentCollection<int, Article> $needing */
        $needing = $articles->filter(function (Article $article): bool {
            $path = $article->main_image_path;

            return ! is_string($path) || $path === ''
                || ! Storage::disk(Article::IMAGE_DISK)->exists($path);
        })->take($limit)->values();

        return $needing;
    }

    private function fillArticleImage(Article $article, string $apiKey, bool $dryRun): void
    {
        $query = $this->searchTermFor($article);

        $response = Http::withHeaders(['Authorization' => $apiKey])
            ->get(self::API_BASE.'/search', [
                'query' => $query,
                'per_page' => 1,
                'orientation' => 'landscape',
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Pexels rate limit reached (429).');
        }

        if ($response->failed()) {
            throw new RuntimeException("Pexels search failed ({$response->status()}) for query \"{$query}\".");
        }

        /** @var list<array<string, mixed>> $photos */
        $photos = $response->json('photos') ?? [];

        if ($photos === []) {
            throw new RuntimeException("No Pexels results for query \"{$query}\".");
        }

        $this->downloadInto($article, $photos[0], $dryRun);
    }

    /**
     * @param  array<string, mixed>  $photo  One Pexels search result.
     */
    private function downloadInto(Article $article, array $photo, bool $dryRun): void
    {
        /** @var array<string, mixed> $src */
        $src = is_array($photo['src'] ?? null) ? $photo['src'] : [];
        $url = $src['large'] ?? $src['medium'] ?? $src['original'] ?? null;

        if (! is_string($url)) {
            throw new RuntimeException('Pexels result carried no usable image URL.');
        }

        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Could not download {$url} ({$response->status()}).");
        }

        $bytes = $response->body();
        $path = Article::IMAGE_DIRECTORY.'/'.$article->slug.'.jpg';

        $this->assertValidImage($bytes, $path);

        if ($dryRun) {
            return;
        }

        Storage::disk(Article::IMAGE_DISK)->put($path, $bytes);
        $article->update(['main_image_path' => $path]);
    }

    /**
     * Re-checks exactly what `ArticleForm`'s `FileUpload` field would have
     * checked, since this command writes the file directly rather than
     * through that form.
     */
    private function assertValidImage(string $bytes, string $path): void
    {
        $tmp = tmpfile();

        if ($tmp === false) {
            throw new RuntimeException('Could not open a temp file to validate the downloaded image.');
        }

        fwrite($tmp, $bytes);
        $meta = stream_get_meta_data($tmp);
        $tmpPath = $meta['uri'] ?? null;

        if (! is_string($tmpPath)) {
            fclose($tmp);

            throw new RuntimeException('Could not determine the temp file path for the downloaded image.');
        }

        $info = @getimagesize($tmpPath);
        fclose($tmp);

        if ($info === false) {
            throw new RuntimeException("Downloaded file for {$path} is not a readable image.");
        }

        [$width, $height, $type] = $info;

        $mime = image_type_to_mime_type($type);

        if (! in_array($mime, Article::IMAGE_ACCEPTED_MIME_TYPES, true)) {
            throw new RuntimeException("Downloaded file for {$path} has an unsupported MIME type ({$mime}).");
        }

        if ($width < Article::IMAGE_MIN_WIDTH_PX || $height < Article::IMAGE_MIN_HEIGHT_PX) {
            throw new RuntimeException("Downloaded file for {$path} is too small ({$width}x{$height}).");
        }

        $sizeKb = strlen($bytes) / 1024;

        if ($sizeKb > Article::IMAGE_MAX_SIZE_KB) {
            throw new RuntimeException("Downloaded file for {$path} is too large ({$sizeKb} KB).");
        }
    }

    /**
     * A search term built from the article's title and category. The title
     * alone is usually specific enough; category is appended as a fallback
     * relevance signal, the same reasoning `FetchDemoImages::searchTermFor()`
     * gives for products.
     */
    private function searchTermFor(Article $article): string
    {
        $parts = [$article->title];

        /** @var ArticleCategory|null $category */
        $category = $article->articleCategory;

        if ($category !== null) {
            $parts[] = $category->name;
        }

        $term = implode(' ', $parts);

        return Str::limit($term, 80, '');
    }
}
