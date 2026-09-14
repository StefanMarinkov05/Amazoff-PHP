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
 * Thousands of products, for catalogue-scale query-plan and pagination
 * testing — browsing, search, and category-listing performance at a size
 * the 169-product demo catalogue cannot exercise. Companion to
 * `StressSeeder`'s order-volume pass; the two are independent and either
 * can run without the other, but `StressSeeder` needs a large catalogue
 * to draw from if it is also run at high volume, since the demo
 * catalogue's `ProtectedSkus`-filtered pool of ~200 variations becomes the
 * bottleneck well before 50,000 orders (`StressSeeder`'s own docblock: a
 * 2,000-order run against the demo catalogue alone already produced
 * failures once the pool ran dry).
 *
 * Deliberately **not** built through `CreateProduct`/`AddProductVariation`
 * — those Actions each open their own transaction, run policy checks, and
 * are priced for one call per admin action, not thousands per second.
 * This inserts directly, the same call `FixtureLoader` and `DemoSeeder`
 * already make for trusted, system-generated catalogue data (ADR-0007
 * permits a null actor for exactly this reason). Every row still respects
 * the schema's own invariants by construction — exactly one `is_default`
 * variation per product, `reserved_quantity` always 0 at creation so
 * `ReserveStock` has real stock to work with — rather than by going
 * through the Actions that would otherwise enforce them.
 *
 * Every stress product gets **exactly one `product_images` row, pointing
 * at a single shared placeholder file** — not a real photo. Catalogue
 * scale is about row count and query shape, not visual fidelity; spending
 * an API call per stress product on `demo:fetch-images`'s source would be
 * both pointless and, at this volume, would exhaust any free-tier image
 * API in minutes. The placeholder lives at
 * `storage/app/public/demo/stress-placeholder.png` — copied
 * once from `public/images/logo.png` the first time this seeder runs, not
 * committed to git as a duplicate. It sits under the seed prefix because it
 * is generated fixture content, not an upload; see ADR-0025.
 *
 * Categories, brands, and attributes are **reused from the existing
 * pool**, not created per product — `ProductFactory`'s own default
 * (`ProductCategory::factory()`, `Brand::factory()`) would otherwise
 * multiply those 1:1 with products, turning a catalogue-scale test into a
 * category-scale test nobody asked for.
 *
 * NOTE for later, not built here: category- and tag-*driven* attribute
 * assignment for real (non-stress) catalogue data — i.e. the system
 * deciding which attributes a product's category implies, rather than a
 * fixture author choosing them by hand — is an open design question the
 * catalogue authoring workflow will need eventually. Out of scope for
 * this stress seeder, which does not attach `attribute_values` to its
 * variations at all (`CreateOrder`'s `variationName()` falls back to the
 * SKU when a variation carries none, so nothing downstream requires it).
 *
 * Run explicitly, never in CI, never wired into `DatabaseSeeder`:
 *
 *     php artisan db:seed --class="Database\Seeders\Stress\CatalogueStressSeeder"
 *     CATALOGUE_STRESS_COUNT=5000 php artisan db:seed --class="Database\Seeders\Stress\CatalogueStressSeeder"
 */
class CatalogueStressSeeder extends Seeder
{
    private const DEFAULT_COUNT = 5000;

    private const CHUNK_SIZE = 500;

    private const PLACEHOLDER_SOURCE = 'public/images/logo.png';

    private const PLACEHOLDER_PATH = ProductImage::SEED_DIRECTORY.'/stress-placeholder.png';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $configuredCount = config('stress.catalogue_count');

        if ($configuredCount !== null && ! is_scalar($configuredCount)) {
            throw new InvalidArgumentException('Config value [stress.catalogue_count] must be a scalar value.');
        }

        $count = (int) ($configuredCount ?? self::DEFAULT_COUNT);

        if ($count < 1) {
            $this->command?->warn('CatalogueStressSeeder: count must be at least 1, got '.$count.'. Skipping.');

            return;
        }

        /** @var Collection<int, int> $categoryIds */
        $categoryIds = ProductCategory::query()->doesntHave('children')->pluck('id');

        /** @var Collection<int, int> $brandIds */
        $brandIds = Brand::query()->pluck('id');

        if ($categoryIds->isEmpty()) {
            $this->command?->warn('CatalogueStressSeeder: no leaf categories found — seed the catalogue first.');

            return;
        }

        $this->ensurePlaceholderImage();

        $started = microtime(true);
        $maxId = DB::table('products')->max('id');

        if ($maxId !== null && ! is_scalar($maxId)) {
            throw new InvalidArgumentException('products.id max() returned a non-scalar value.');
        }

        $startId = (int) ($maxId ?? 0) + 1;

        $this->command?->info("CatalogueStressSeeder: creating {$count} product(s)...");

        $created = 0;

        foreach (array_chunk(range($startId, $startId + $count - 1), self::CHUNK_SIZE) as $idBatch) {
            $this->insertBatch($idBatch, $categoryIds, $brandIds);
            $created += count($idBatch);

            if ($created % 2000 === 0 || $created === $count) {
                $this->command?->info("  {$created}/{$count}...");
            }
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->command?->info("CatalogueStressSeeder: created {$created} product(s), each with 1 variation, 1 inventory row, and 1 placeholder image, in {$elapsed}s.");
    }

    /**
     * @param  list<int>  $sequenceNumbers  Used only to derive deterministic
     *                                      unique slugs/SKUs — not the real
     *                                      product id, which is
     *                                      auto-increment and may differ if
     *                                      rows were deleted and re-seeded.
     * @param  Collection<int, int>  $categoryIds
     * @param  Collection<int, int>  $brandIds
     */
    private function insertBatch(array $sequenceNumbers, Collection $categoryIds, Collection $brandIds): void
    {
        $now = Carbon::now();

        $products = [];
        $skuByProductSlot = [];

        foreach ($sequenceNumbers as $i => $seq) {
            $sku = sprintf('STRESS-%08d', $seq);
            $skuByProductSlot[$i] = $sku;

            $regularPrice = number_format(mt_rand(500, 200000) / 100, 2, '.', '');

            $products[] = [
                'product_category_id' => $categoryIds->random(),
                'brand_id' => $brandIds->isNotEmpty() ? $brandIds->random() : null,
                'name' => "Stress Product {$seq}",
                'slug' => Str::slug("stress-product-{$seq}"),
                'sku' => $sku,
                'short_description' => 'Generated for catalogue-scale stress testing.',
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

        $variations = [];
        $images = [];

        foreach ($skuByProductSlot as $sku) {
            $productId = $productIdBySku[$sku];

            $variations[] = [
                'product_id' => $productId,
                'sku' => $sku.'-STD',
                'price' => null,
                'discount_price' => null,
                'weight_g' => null,
                'weight_display_unit' => WeightUnit::Gram->value,
                'length_mm' => null,
                'width_mm' => null,
                'height_mm' => null,
                'is_available' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $images[] = [
                'product_id' => $productId,
                'path' => self::PLACEHOLDER_PATH,
                'alt_text' => null,
                'is_main' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('product_variations')->insert($variations);

        /** @var array<string, int> $variationIdBySku */
        $variationIdBySku = DB::table('product_variations')
            ->whereIn('sku', array_column($variations, 'sku'))
            ->pluck('id', 'sku')
            ->all();

        $inventories = [];

        foreach ($variationIdBySku as $variationId) {
            $current = mt_rand(0, 500);

            $inventories[] = [
                'product_variation_id' => $variationId,
                'current_quantity' => $current,
                // Always 0 at creation — a factory-style random pre-reserved
                // quantity would corrupt ReserveStock's accounting the first
                // time a stress order tries to reserve against this row.
                'reserved_quantity' => 0,
                'sold_quantity' => mt_rand(0, 200),
                'returned_quantity' => 0,
                'damaged_quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('inventories')->insert($inventories);
        DB::table('product_images')->insert($images);
    }

    /**
     * Copies `logo.png` to the real upload disk once, at the path every
     * stress product's `product_images.path` points at. Idempotent — a
     * second run finds the file already there and does nothing.
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
