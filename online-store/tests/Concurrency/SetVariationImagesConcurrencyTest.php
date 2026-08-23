<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\SetVariationImages;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Two administrators editing one variation's gallery, and one editing it while
 * another deletes an image out from under them.
 *
 * Both are the third assertion shape in
 * `explanation/concurrency-and-locking.md`: the property being asserted is the
 * final state, not a refusal. SetVariationImages writes a complete set under
 * the products lock, so there is no interleaving that can leave a half-written
 * gallery — the loser's set is discarded whole, which is the documented and
 * accepted outcome for a display ordering. ADR-0013 ·
 * reference/write-rules/product-variation-images.md
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_image_product_variation',
        'inventory_movements',
        'inventories',
        'product_variations',
        'product_images',
        'product_specifications',
        'products',
        'product_categories',
        'brands',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

/**
 * A product, one variation, and `$count` images belonging to that product.
 *
 * @return array{0: ProductVariation, 1: list<ProductImage>}
 */
function raceGallery(int $count = 3): array
{
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => 'RACE-'.bin2hex(random_bytes(6)),
        'price' => '19.99',
        'is_available' => true,
    ], 0, null);

    $images = [];

    for ($i = 0; $i < $count; $i++) {
        $images[] = app(AddProductImage::class)->handle($product, [
            'path' => 'product-images/race-'.$i.'-'.bin2hex(random_bytes(4)).'.jpg',
            'sort_order' => $i,
        ], null);
    }

    return [$variation, $images];
}

it('leaves one complete gallery when two are written at once', function (): void {
    [$variation, $images] = raceGallery();

    // Two administrators submitting different, complete galleries. Disjoint
    // on purpose: if the write were a per-row attach/detach rather than a set
    // replacement, an interleaving could leave rows from both.
    $outputs = runRaceWorkers([
        [
            'action' => 'set-variation-images',
            'ids' => [$variation->getKey(), $images[0]->getKey(), $images[1]->getKey()],
        ],
        [
            'action' => 'set-variation-images',
            'ids' => [$variation->getKey(), $images[2]->getKey()],
        ],
    ]);
    $report = raceReport($outputs);

    // Both are legitimate operations, so both should report success. Neither
    // succeeding means the workers failed to boot rather than that locking
    // works — see troubleshooting.md on this suite's load sensitivity.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(2, $report);

    $final = DB::table('product_image_product_variation')
        ->where('product_variation_id', $variation->getKey())
        ->orderBy('position')
        ->pluck('product_image_id')
        ->all();

    // One winner, whole. A mixture of the two submissions would mean the set
    // is not being replaced atomically under the lock.
    expect($final)->toBeIn([
        [$images[0]->getKey(), $images[1]->getKey()],
        [$images[2]->getKey()],
    ], 'The gallery is a mixture of two concurrent submissions, so '.
        'SetVariationImages is no longer replacing the set atomically.'.$report);
});

it('never leaves a gap in the positions of the winning gallery', function (): void {
    [$variation, $images] = raceGallery();

    $outputs = runRaceWorkers([
        [
            'action' => 'set-variation-images',
            'ids' => [$variation->getKey(), $images[0]->getKey(), $images[1]->getKey(), $images[2]->getKey()],
        ],
        [
            'action' => 'set-variation-images',
            'ids' => [$variation->getKey(), $images[2]->getKey(), $images[0]->getKey()],
        ],
    ]);
    $report = raceReport($outputs);

    $positions = DB::table('product_image_product_variation')
        ->where('product_variation_id', $variation->getKey())
        ->orderBy('position')
        ->pluck('position')
        ->all();

    // Contiguous from 1 is a property of the write, not of a repair pass —
    // nothing renumbers, because nothing writes a partial set.
    expect($positions)->toBe(range(1, count($positions)), $report);
});

it('does not leave a gallery row pointing at a deleted image', function (): void {
    [$variation, $images] = raceGallery();

    // Seeded so the image being deleted is already in the gallery: the losing
    // order has to be survivable in both directions, not just the easy one.
    app(SetVariationImages::class)->handle($variation, [$images[1]->getKey()], null);

    $outputs = runRaceWorkers([
        [
            'action' => 'set-variation-images',
            'ids' => [$variation->getKey(), $images[0]->getKey(), $images[1]->getKey()],
            'rendezvous' => 'writer',
        ],
        [
            'action' => 'remove-image',
            'ids' => [$images[1]->getKey()],
            'rendezvous' => 'remover',
        ],
    ]);
    $report = raceReport($outputs);

    // Asymmetric by nature, and worth saying rather than asserting past: if
    // the removal commits first the gallery write refuses that id with
    // ImageNotOnProductException, and if the write commits first the removal
    // cascades the row away. Both are correct; which happens is timing.
    $orphans = DB::table('product_image_product_variation as pivot')
        ->leftJoin('product_images', 'product_images.id', '=', 'pivot.product_image_id')
        ->whereNull('product_images.id')
        ->count();

    expect($orphans)->toBe(
        0,
        'A gallery row survives an image that no longer exists. The pivot '.
        'foreign key is no longer cascading.'.$report,
    );

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->not->toBeEmpty($report);
});
