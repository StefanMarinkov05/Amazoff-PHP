<?php

declare(strict_types=1);

namespace Database\Seeders\Stress;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * `CatalogueStressSeeder`'s companion for *depth* rather than breadth: many
 * variations and a real per-product image gallery, not one of each. That
 * seeder gives every product exactly 1 variation and 1 image — enough to
 * stress catalogue-scale browsing/search/pagination (row *count*), but
 * nothing there exercises a product detail page's variation switcher or
 * gallery at a size a single hand-authored demo product (5 variations, 2
 * images — the seeded catalogue's own current maximum) cannot approach.
 *
 * Every product gets `min_variations`–`max_variations` variations (default
 * 20–30) and up to `images_per_product` (default 50) images of its own —
 * `product_images` is product-owned in this schema (`product_id` NOT NULL,
 * no cross-product row sharing — see the pivot migration's own docblock),
 * so "shared images" means shared *within* one product's gallery via
 * `product_image_product_variation`, not shared across products. Each
 * variation gets a real gallery — a random subset of that product's own
 * image pool, 3–8 images, attached through the pivot exactly the way
 * `SetVariationImages` would. No `product_variations.image_id` to set —
 * that column was dropped (see `insertBatch()`'s own note) — a
 * variation's image resolves through this pivot alone.
 *
 * Same non-Action, direct-insert shape as `CatalogueStressSeeder`, for the
 * same reason: `CreateProduct`/`AddProductVariation`/`SetVariationImages`
 * each open a transaction and run policy checks, priced for one admin
 * click, not millions of rows. Every row still respects the schema's own
 * invariants by construction: exactly one `is_default` variation per
 * product, `reserved_quantity` always 0 at creation, every pivot row
 * referencing rows inserted earlier in the same batch.
 *
 * At the defaults (100,000 products, 20–30 variations, up to 50 images)
 * this seeds exactly 50 image rows per product regardless of variation
 * count — `imagesPerProduct` is fixed, not randomised — so the totals are
 * as predictable as the product count: 100,000 products, ~2.5M variations
 * (20–30 randomised per product), 5,000,000 images (50 × 100,000 flat),
 * ~2.5M inventory rows, and tens of millions of gallery-pivot rows (each
 * variation's own randomised 3–8). Measured at these defaults: 723.3s
 * (12m 3s), 138.3 products/second, scaling linearly the whole run — see
 * `reference/testing/performance-testing.md` for the full measured
 * results and `how-to/measure-performance-under-load.md` for the
 * reproducible procedure. This is deliberately the largest seeder in the
 * repository.
 *
 * Run explicitly, never in CI, never wired into `DatabaseSeeder`:
 *
 *     php artisan db:seed --class="Database\Seeders\Stress\DeepCatalogueStressSeeder"
 *     DEEP_STRESS_COUNT=1000 php artisan db:seed --class="Database\Seeders\Stress\DeepCatalogueStressSeeder"
 */
class DeepCatalogueStressSeeder extends Seeder
{
    private const DEFAULT_COUNT = 100000;

    private const DEFAULT_MIN_VARIATIONS = 20;

    private const DEFAULT_MAX_VARIATIONS = 30;

    private const DEFAULT_IMAGES_PER_PRODUCT = 50;

    private const MIN_IMAGES_PER_VARIATION_GALLERY = 3;

    private const MAX_IMAGES_PER_VARIATION_GALLERY = 8;

    // Products per outer batch. Kept far below CatalogueStressSeeder's 500 —
    // each product here fans out into ~20-30 variations, ~50 images, and
    // ~20-30 × 3-8 pivot rows, so a 500-product batch would build single
    // INSERTs of hundreds of thousands of rows. 50 keeps each batch's
    // largest single insert (the gallery pivot) in the tens of thousands.
    private const PRODUCT_BATCH_SIZE = 50;

    private const PLACEHOLDER_SOURCE = 'public/images/logo.png';

    private const PLACEHOLDER_PATH = ProductImage::SEED_DIRECTORY.'/stress-placeholder.png';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $count = $this->intConfig('stress.deep_catalogue_count', self::DEFAULT_COUNT);
        $minVariations = $this->intConfig('stress.deep_min_variations', self::DEFAULT_MIN_VARIATIONS);
        $maxVariations = $this->intConfig('stress.deep_max_variations', self::DEFAULT_MAX_VARIATIONS);
        $imagesPerProduct = $this->intConfig('stress.deep_images_per_product', self::DEFAULT_IMAGES_PER_PRODUCT);

        if ($count < 1) {
            $this->command?->warn('DeepCatalogueStressSeeder: count must be at least 1, got '.$count.'. Skipping.');

            return;
        }

        if ($minVariations < 1 || $maxVariations < $minVariations) {
            $this->command?->warn('DeepCatalogueStressSeeder: min/max variations are inconsistent. Skipping.');

            return;
        }

        if ($imagesPerProduct < self::MAX_IMAGES_PER_VARIATION_GALLERY) {
            $this->command?->warn('DeepCatalogueStressSeeder: images_per_product must be at least '.self::MAX_IMAGES_PER_VARIATION_GALLERY.'. Skipping.');

            return;
        }

        /** @var Collection<int, int> $categoryIds */
        $categoryIds = ProductCategory::query()->doesntHave('children')->pluck('id');

        /** @var Collection<int, int> $brandIds */
        $brandIds = Brand::query()->pluck('id');

        if ($categoryIds->isEmpty()) {
            $this->command?->warn('DeepCatalogueStressSeeder: no leaf categories found — seed the catalogue first.');

            return;
        }

        $this->ensurePlaceholderImage();

        $started = microtime(true);
        $maxId = DB::table('products')->max('id');

        if ($maxId !== null && ! is_scalar($maxId)) {
            throw new InvalidArgumentException('products.id max() returned a non-scalar value.');
        }

        $startId = (int) ($maxId ?? 0) + 1;

        $this->command?->info("DeepCatalogueStressSeeder: creating {$count} product(s), {$minVariations}-{$maxVariations} variations and up to {$imagesPerProduct} images each...");

        $created = 0;
        $totalVariations = 0;
        $totalImages = 0;
        $totalPivotRows = 0;

        foreach (array_chunk(range($startId, $startId + $count - 1), self::PRODUCT_BATCH_SIZE) as $idBatch) {
            $stats = $this->insertBatch($idBatch, $categoryIds, $brandIds, $minVariations, $maxVariations, $imagesPerProduct);

            $created += count($idBatch);
            $totalVariations += $stats['variations'];
            $totalImages += $stats['images'];
            $totalPivotRows += $stats['pivotRows'];

            if ($created % 2000 === 0 || $created === $count) {
                $elapsedSoFar = round(microtime(true) - $started, 1);
                $this->command?->info("  {$created}/{$count} products, {$totalVariations} variations, {$totalImages} images, {$totalPivotRows} gallery rows so far ({$elapsedSoFar}s)...");
            }
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->command?->info("DeepCatalogueStressSeeder: created {$created} product(s), {$totalVariations} variation(s), {$totalImages} image(s), {$totalPivotRows} gallery-pivot row(s), in {$elapsed}s.");
    }

    /**
     * @param  list<int>  $sequenceNumbers  Used only to derive deterministic
     *                                      unique slugs/SKUs — not the real
     *                                      product id, which is
     *                                      auto-increment and may differ if
     *                                      rows were deleted and re-seeded.
     * @param  Collection<int, int>  $categoryIds
     * @param  Collection<int, int>  $brandIds
     * @return array{variations: int, images: int, pivotRows: int}
     */
    private function insertBatch(
        array $sequenceNumbers,
        Collection $categoryIds,
        Collection $brandIds,
        int $minVariations,
        int $maxVariations,
        int $imagesPerProduct,
    ): array {
        $now = Carbon::now();

        $products = [];
        $skuByProductSlot = [];

        foreach ($sequenceNumbers as $i => $seq) {
            $sku = sprintf('DEEP-%08d', $seq);
            $skuByProductSlot[$i] = $sku;

            $regularPrice = number_format(mt_rand(500, 200000) / 100, 2, '.', '');

            $products[] = [
                'product_category_id' => $categoryIds->random(),
                'brand_id' => $brandIds->isNotEmpty() ? $brandIds->random() : null,
                'name' => "Deep Stress Product {$seq}",
                'slug' => Str::slug("deep-stress-product-{$seq}"),
                'sku' => $sku,
                'short_description' => 'Generated for variation/image-depth stress testing.',
                'description' => null,
                'regular_price' => $regularPrice,
                'discount_price' => null,
                'discount_starts_at' => null,
                'discount_ends_at' => null,
                'vat_rate' => 20.00,
                'min_order_quantity' => 1,
                'length_mm' => mt_rand(50, 1000),
                'width_mm' => mt_rand(50, 1000),
                'height_mm' => mt_rand(50, 1000),
                'dimension_display_unit' => LengthUnit::Millimetre->value,
                'weight_g' => mt_rand(50, 20000),
                'weight_display_unit' => WeightUnit::Gram->value,
                'is_available' => true,
                'is_featured' => false,
                'seo_title' => null,
                'seo_description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('products')->insert($products);

        /** @var array<string, int> $productIdBySku */
        $productIdBySku = DB::table('products')
            ->whereIn('sku', $skuByProductSlot)
            ->pluck('id', 'sku')
            ->all();

        // --- Images: up to $imagesPerProduct per product, all pointing at
        // the one shared placeholder file — distinct rows, not distinct
        // files, the same trade CatalogueStressSeeder makes.
        $images = [];
        $imageSlotsByProductId = [];

        foreach ($skuByProductSlot as $sku) {
            $productId = $productIdBySku[$sku];
            $imageSlotsByProductId[$productId] = [];

            for ($pos = 0; $pos < $imagesPerProduct; $pos++) {
                $images[] = [
                    'product_id' => $productId,
                    'path' => self::PLACEHOLDER_PATH,
                    'alt_text' => null,
                    'is_main' => $pos === 0,
                    'sort_order' => $pos,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('product_images')->insert($images);

        // Pull every image id just inserted, grouped by product, in
        // (product_id, sort_order) order — sort_order is unique per product
        // by construction above, so this recovers each row's identity
        // without a second round-trip per product.
        $insertedImages = DB::table('product_images')
            ->whereIn('product_id', array_keys($imageSlotsByProductId))
            ->where('created_at', $now)
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->get(['id', 'product_id']);

        foreach ($insertedImages as $row) {
            $imageSlotsByProductId[$row->product_id][] = $row->id;
        }

        // --- Variations: min-max per product, one flagged is_default.
        $variations = [];
        $variationSkuToProductId = [];
        $variationSkusByProductId = [];

        foreach ($skuByProductSlot as $sku) {
            $productId = $productIdBySku[$sku];
            $variationCount = mt_rand($minVariations, $maxVariations);
            $variationSkusByProductId[$productId] = [];

            for ($v = 0; $v < $variationCount; $v++) {
                $variationSku = "{$sku}-V{$v}";
                $variationSkuToProductId[$variationSku] = $productId;
                $variationSkusByProductId[$productId][] = $variationSku;

                $variations[] = [
                    'product_id' => $productId,
                    'sku' => $variationSku,
                    'price' => null,
                    'discount_price' => null,
                    'weight_g' => null,
                    'weight_display_unit' => WeightUnit::Gram->value,
                    'length_mm' => null,
                    'width_mm' => null,
                    'height_mm' => null,
                    'is_available' => true,
                    'is_default' => $v === 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('product_variations')->insert($variations);

        $insertedVariations = DB::table('product_variations')
            ->whereIn('sku', array_keys($variationSkuToProductId))
            ->get(['id', 'sku', 'product_id']);

        // --- Inventory: one row per variation, reserved always 0.
        $inventories = [];

        foreach ($insertedVariations as $row) {
            $inventories[] = [
                'product_variation_id' => $row->id,
                'current_quantity' => mt_rand(0, 500),
                'reserved_quantity' => 0,
                'sold_quantity' => mt_rand(0, 200),
                'returned_quantity' => 0,
                'damaged_quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('inventories')->insert($inventories);

        // --- Gallery pivot: each variation gets a random subset (3-8) of
        // its own product's image pool. No `product_variations.image_id`
        // to update afterward — that column was dropped
        // (2026_08_23_093000_drop_image_id_from_product_variations_table);
        // `ResolveVariationImage::current()` reads `$variation->images()
        // ->first()` straight off this pivot, ordered by `position`, so the
        // lowest-position row inserted below is already every variation's
        // resolved image with nothing further to write.
        $pivotRows = [];

        foreach ($insertedVariations as $row) {
            /** @var list<int> $pool */
            $pool = $imageSlotsByProductId[$row->product_id];
            $galleryCount = min(count($pool), mt_rand(self::MIN_IMAGES_PER_VARIATION_GALLERY, self::MAX_IMAGES_PER_VARIATION_GALLERY));

            // shuffle()+array_slice() rather than array_rand(), which
            // returns a bare scalar (not a single-element array) when asked
            // for exactly one key — a type-unsafe special case worth
            // avoiding rather than normalising after the fact.
            $shuffledPool = $pool;
            shuffle($shuffledPool);
            /** @var list<int> $chosen */
            $chosen = array_slice($shuffledPool, 0, $galleryCount);

            foreach ($chosen as $position => $imageId) {
                $pivotRows[] = [
                    'product_image_id' => $imageId,
                    'product_variation_id' => $row->id,
                    'position' => $position,
                ];
            }
        }

        DB::table('product_image_product_variation')->insert($pivotRows);

        return [
            'variations' => count($variations),
            'images' => count($images),
            'pivotRows' => count($pivotRows),
        ];
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key);

        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException("Config value [{$key}] must be a scalar value.");
        }

        return (int) ($value ?? $default);
    }

    /**
     * Copies `logo.png` to the real upload disk once, at the path every
     * stress product's `product_images.path` points at. Idempotent — a
     * second run finds the file already there and does nothing. Shared with
     * `CatalogueStressSeeder`'s own placeholder — same path, same file — so
     * running both seeders in the same database does not duplicate it.
     */
    private function ensurePlaceholderImage(): void
    {
        if (Storage::disk(ProductImage::SEED_DISK)->exists(self::PLACEHOLDER_PATH)) {
            return;
        }

        $source = base_path(self::PLACEHOLDER_SOURCE);

        if (! is_file($source)) {
            throw new RuntimeException(self::PLACEHOLDER_SOURCE.' is missing — cannot create the stress placeholder image.');
        }

        Storage::disk(ProductImage::SEED_DISK)->put(self::PLACEHOLDER_PATH, (string) file_get_contents($source));
    }
}
