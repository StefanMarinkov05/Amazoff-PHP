<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Downloads real photos from Pexels for every `product_images` row whose
 * `path` does not exist on disk, and writes them at that exact path.
 *
 * Why this exists: the demo catalogue's fixture documents were authored with
 * placeholder `path` values (`demo/clm0001-main.jpg`) on the expectation that
 * real files would be dropped in later. Nothing ever did — every product
 * image in the running app was broken. This command is that later step, run
 * once, not a permanent part of the seed chain — it is not wired into any
 * `Demo*` seeder and never should be.
 *
 * Third source tried in this repo's history, for a reason worth recording so
 * nobody reaches for either of the first two again:
 *
 * 1. **Unsplash** — worked correctly (0 contaminated files across 25
 *    downloaded), but its free tier's 50-requests/hour cap meant the full
 *    ~162-product catalogue needed several runs spread across a day.
 * 2. **Apify's `hooli/google-images-scraper`** — tried for speed (no hourly
 *    cap), but a real run showed roughly 40% of downloaded "images" were
 *    actually a hotlink-protection placeholder graphic ("This site does not
 *    have permission to serve this content") that arbitrary scraped CDN
 *    URLs serve instead of the real photo. A content-based detector
 *    (GD colour-variance sampling) was built to catch this and still missed
 *    a large share — JPEG compression and antialiased, rotated placeholder
 *    text manufacture enough colour variety to defeat a cheap heuristic.
 *    Abandoned rather than iterated further once real data showed the
 *    detection approach itself does not generalise.
 * 3. **Pexels** (this version) — same reliability shape as Unsplash
 *    (licensed photo API, not a scraper hitting arbitrary third-party
 *    hosts; image URLs are Pexels' own CDN), but a 25,000-requests/hour
 *    free tier, so the whole catalogue fits in one run with no batching or
 *    resumability complexity needed.
 *
 * Still validates each downloaded file against the exact constants
 * `ProductImagesRelationManager`'s form enforces
 * (`ProductImage::MIN_WIDTH_PX`/`MIN_HEIGHT_PX`/`ACCEPTED_MIME_TYPES`/
 * `MAX_SIZE_KB`) before accepting it — those checks live only in the
 * Filament form layer today (`AddProductImage` itself does not check them),
 * and this command bypasses that form the same way `FixtureLoader` bypasses
 * it for the rest of the catalogue. No content-based placeholder detector
 * this time: a licensed photo API serving its own CDN URLs does not need
 * one, and #2 above is the record of why that kind of check is not trusted
 * to substitute for a reliable source.
 *
 * Still resumable regardless — every invocation skips any product whose
 * expected image files already exist on disk — in case a run is interrupted
 * or the rate limit is ever actually hit.
 *
 * Requires `PEXELS_API_KEY` in `.env` — a free key from
 * pexels.com/api, no payment info needed.
 */
final class FetchDemoImages extends Command
{
    protected $signature = 'demo:fetch-images
        {--limit= : Maximum products to process this run (default: all still missing)}
        {--dry-run : Report what would be fetched without writing anything}';

    protected $description = 'Download real Pexels photos for demo product_images rows still pointing at placeholder paths';

    private const API_BASE = 'https://api.pexels.com/v1';

    public function handle(): int
    {
        $apiKey = config('services.pexels.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            $this->error('PEXELS_API_KEY is not set in .env. Get a free key at pexels.com/api.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        $limit = is_string($limitOption) ? (int) $limitOption : PHP_INT_MAX;
        $dryRun = (bool) $this->option('dry-run');

        $products = $this->productsNeedingImages($limit);

        if ($products->isEmpty()) {
            $this->info('Every product_images row already has a real file on disk. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d product(s) still need real images%s.',
            $products->count(),
            $dryRun ? ' — dry run, nothing will be written' : '',
        ));

        $fetched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($products as $product) {
            try {
                $count = $this->fillProductImages($product, $apiKey, $dryRun);

                if ($count === 0) {
                    $skipped++;

                    continue;
                }

                $fetched += $count;
                $this->line("  {$product->sku}: {$count} image(s) written.");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  {$product->sku}: failed — {$e->getMessage()}");
            }
        }

        $this->info("Done. {$fetched} image file(s) written, {$skipped} product(s) skipped, {$failed} failed.");

        if (! $dryRun) {
            $remaining = $this->productsNeedingImages(PHP_INT_MAX)->count();

            if ($remaining > 0) {
                $this->info("{$remaining} product(s) still remain — re-run this command to continue.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Products with at least one `product_images` row whose file is missing
     * on the public disk — the ones this run still has to fix.
     *
     * @return EloquentCollection<int, Product>
     */
    private function productsNeedingImages(int $limit): EloquentCollection
    {
        /** @var EloquentCollection<int, Product> $products */
        $products = Product::query()
            ->with(['productImages', 'productCategory', 'brand'])
            ->whereHas('productImages')
            ->get();

        /** @var EloquentCollection<int, Product> $needing */
        $needing = $products->filter(function (Product $p): bool {
            /** @var EloquentCollection<int, ProductImage> $images */
            $images = $p->productImages;

            return $images->contains(
                fn (ProductImage $img): bool => ! Storage::disk(ProductImage::SEED_DISK)->exists($img->path)
            );
        })->take($limit)->values();

        return $needing;
    }

    /**
     * One Pexels search per product, writing every one of its missing image
     * rows from that same result set — a different result per row where
     * available, the last result repeated if the product has more image
     * rows than results came back.
     *
     * @return int Image files actually written.
     */
    private function fillProductImages(Product $product, string $apiKey, bool $dryRun): int
    {
        /** @var EloquentCollection<int, ProductImage> $productImages */
        $productImages = $product->productImages;

        $missing = $productImages->filter(
            fn (ProductImage $img): bool => ! Storage::disk(ProductImage::SEED_DISK)->exists($img->path)
        );

        if ($missing->isEmpty()) {
            return 0;
        }

        $query = $this->searchTermFor($product);

        $response = Http::withHeaders(['Authorization' => $apiKey])
            ->get(self::API_BASE.'/search', [
                'query' => $query,
                'per_page' => min(10, max(1, $missing->count())),
                'orientation' => 'square',
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

        $written = 0;

        foreach ($missing->values() as $index => $image) {
            $photo = $photos[$index] ?? $photos[array_key_last($photos)];
            $this->downloadInto($image, $photo, $dryRun);
            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $photo  One Pexels search result.
     */
    private function downloadInto(ProductImage $image, array $photo, bool $dryRun): void
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

        $this->assertValidImage($bytes, $image->path);

        if ($dryRun) {
            return;
        }

        Storage::disk(ProductImage::SEED_DISK)->put($image->path, $bytes);
    }

    /**
     * Re-checks exactly what `ProductImagesRelationManager`'s form would
     * have checked, since this command writes the file directly rather than
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

        if (! in_array($mime, ProductImage::ACCEPTED_MIME_TYPES, true)) {
            throw new RuntimeException("Downloaded file for {$path} has an unsupported MIME type ({$mime}).");
        }

        if ($width < ProductImage::MIN_WIDTH_PX || $height < ProductImage::MIN_HEIGHT_PX) {
            throw new RuntimeException("Downloaded file for {$path} is too small ({$width}x{$height}).");
        }

        $sizeKb = strlen($bytes) / 1024;

        if ($sizeKb > ProductImage::MAX_SIZE_KB) {
            throw new RuntimeException("Downloaded file for {$path} is too large ({$sizeKb} KB).");
        }
    }

    /**
     * A search term built from the product's name and category. The
     * product name alone is usually specific enough ("Classic Crew Neck
     * T-Shirt"); category is appended as a fallback relevance signal, not
     * because the name is assumed to be insufficient.
     */
    private function searchTermFor(Product $product): string
    {
        $parts = [$product->name];

        /** @var ProductCategory|null $category */
        $category = $product->productCategory;

        if ($category !== null) {
            $parts[] = $category->name;
        }

        $term = implode(' ', $parts);

        return Str::limit($term, 80, '');
    }
}
