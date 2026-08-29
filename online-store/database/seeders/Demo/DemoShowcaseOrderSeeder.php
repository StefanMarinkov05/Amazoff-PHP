<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;

/**
 * Assigns `demo_case_order`/`demo_case_label` to 13 real products already in
 * the catalogue, each chosen to showcase one distinguishable case — the
 * staff-only "Demo order" sort on `/catalogue` walks this exact sequence.
 * `docs/reference/demo-showcase-order.md` has the reasoning per case; this
 * seeder is the mapping, that page is the why.
 *
 * Matched by `sku`, not `id` — a re-seed from a fresh `migrate:fresh` keeps
 * SKUs stable (they come from the fixture files) while auto-increment ids
 * do not necessarily land the same way twice. A SKU that no longer exists
 * (a fixture changed) is skipped rather than failing the whole run — every
 * other row still needs to land, and a missing showcase case is a gap to
 * notice in the demo, not a reason to break seeding.
 *
 * Must run after `DemoSeeder` (the catalogue) and `DemoReviewSeeder` (case 6
 * needs real reviews already attached) — see `docs/how-to/
 * seed-the-database.md` for the full run order this seeder joins.
 */
class DemoShowcaseOrderSeeder extends Seeder
{
    /**
     * @var list<array{sku: string, label: string}>
     */
    private const CASES = [
        ['sku' => 'CLM-0001', 'label' => 'Multi-image + multi-variation + impossible combo'],
        ['sku' => 'CLM-0014', 'label' => 'One variation, two images'],
        ['sku' => 'CLM-0002', 'label' => 'One image shared across variations'],
        ['sku' => 'WRK-0004', 'label' => 'Four variations (size spread)'],
        ['sku' => 'PWR-0010', 'label' => 'Category is a parent node'],
        ['sku' => 'ELC-0021', 'label' => 'Multiple reviews, mixed ratings'],
        ['sku' => 'CLM-0004', 'label' => 'Active discount'],
        // ELC-0015, not CLM-0016 — CLM-0016 was the first pick and is
        // wrong for this case: is_available=0 (deactivated, hidden by
        // ProductList::applyFilters()'s own gate — and by the demo-mode
        // query's identical is_available=true clause) is a different state
        // from "listed with zero sellable inventory," which is what this
        // case is actually meant to show. ELC-0015 is listed and genuinely
        // out of stock across every variation.
        ['sku' => 'ELC-0015', 'label' => 'Out of stock'],
        ['sku' => 'CLM-0011', 'label' => 'Exactly one unit available'],
        ['sku' => 'HND-0007', 'label' => 'Minimum order quantity above one'],
        ['sku' => 'CLM-0003', 'label' => 'Single variation, no frills'],
        ['sku' => 'CLM-0007', 'label' => 'No reviews yet'],
        ['sku' => 'BTY-0001', 'label' => 'No images at all'],
    ];

    /**
     * The one case with no real counterpart to find — every product in the
     * seeded catalogue has at least one photo (`demo:fetch-images` was run
     * against all 182 `product_images` rows), so "no images" does not exist
     * in the data unless this seeder manufactures it. `BTY-0001` was picked
     * because it is otherwise unremarkable (one variation, no discount, not
     * used by another case) — stripping its images changes nothing else
     * about it, so the empty-gallery UI is the only thing this case tests.
     */
    private const NO_IMAGES_SKU = 'BTY-0001';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // Reset first, not only assign: without this, a product dropped
        // from CASES (a SKU swapped out, exactly what happened when
        // CLM-0016 turned out to be the wrong pick for "out of stock")
        // keeps its stale demo_case_order/label forever — a re-run of this
        // seeder is additive by SKU, never subtractive, so the only way the
        // set stays exactly the current CASES list is to clear everyone
        // first and let the loop below reassign only what belongs.
        Product::query()
            ->whereNotNull('demo_case_order')
            ->update(['demo_case_order' => null, 'demo_case_label' => null]);

        $matched = 0;

        foreach (self::CASES as $position => $case) {
            $updated = Product::query()
                ->where('sku', $case['sku'])
                ->update([
                    'demo_case_order' => $position + 1,
                    'demo_case_label' => $case['label'],
                ]);

            $matched += $updated;
        }

        $this->stripImages();

        $this->command?->info("Demo showcase order: {$matched}/".count(self::CASES).' cases matched.');
    }

    /**
     * Deletes every `product_images` row for the no-images case — the pivot
     * rows in `product_image_product_variation` cascade with it
     * (`cascadeOnDelete()` on `product_image_id`, per that table's own
     * migration), so nothing separate is needed for the variation side.
     * Idempotent: a re-run against a product already stripped deletes zero
     * rows rather than erroring.
     */
    private function stripImages(): void
    {
        $product = Product::query()->where('sku', self::NO_IMAGES_SKU)->first();

        if ($product === null) {
            return;
        }

        ProductImage::query()->where('product_id', $product->id)->delete();
    }
}
