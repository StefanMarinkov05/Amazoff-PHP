<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductVariation;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Two administrators editing one variation's attribute-value combination at
 * once. The third assertion shape in
 * `explanation/concurrency-and-locking.md`: the property being asserted is
 * the final state, not a refusal. SetVariationAttributeValues writes a
 * complete combination under the products lock, so there is no interleaving
 * that can leave a half-written set — the loser's combination is discarded
 * whole. ADR-0005 · reference/write-rules/product-variation-attribute-values.md
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'attribute_value_product_variation',
        'attribute_values',
        'attribute_product',
        'attributes',
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
 * A product with 2 variation axes, one variation, and the axes' own values
 * to draw from.
 *
 * @return array{0: ProductVariation, 1: list<AttributeValue>, 2: list<AttributeValue>}
 */
function raceCombination(): array
{
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => 'RACE-'.bin2hex(random_bytes(6)),
        'price' => '19.99',
        'is_available' => true,
    ], 0, null);

    $scent = Attribute::factory()->create();
    $volume = Attribute::factory()->create();
    $product->attributes()->attach([$scent->id, $volume->id]);

    $scentValues = AttributeValue::factory()->for($scent)->count(2)->create()->all();
    $volumeValues = AttributeValue::factory()->for($volume)->count(2)->create()->all();

    return [$variation, $scentValues, $volumeValues];
}

it('leaves one complete combination when two are written at once', function (): void {
    [$variation, $scentValues, $volumeValues] = raceCombination();

    // Two administrators submitting different, complete combinations.
    // Disjoint on purpose: if the write were per-value attach/detach rather
    // than a set replacement, an interleaving could leave values from both.
    $outputs = runRaceWorkers([
        [
            'action' => 'set-variation-attribute-values',
            'ids' => [$variation->getKey(), $scentValues[0]->getKey(), $volumeValues[0]->getKey()],
        ],
        [
            'action' => 'set-variation-attribute-values',
            'ids' => [$variation->getKey(), $scentValues[1]->getKey(), $volumeValues[1]->getKey()],
        ],
    ]);
    $report = raceReport($outputs);

    // Both are legitimate operations, so both should report success. Neither
    // succeeding means the workers failed to boot rather than that locking
    // works — see how-to/troubleshooting/concurrency-and-testing-races.md on
    // this suite's load sensitivity.
    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(2, $report);

    $final = DB::table('attribute_value_product_variation')
        ->where('product_variation_id', $variation->getKey())
        ->orderBy('attribute_value_id')
        ->pluck('attribute_value_id')
        ->all();

    // One winner, whole. A mixture of the two submissions would mean the
    // set is not being replaced atomically under the lock — and would also
    // be two values for the same attribute, which the Action itself refuses
    // when it can see both at once.
    expect($final)->toBeIn([
        collect([$scentValues[0]->getKey(), $volumeValues[0]->getKey()])->sort()->values()->all(),
        collect([$scentValues[1]->getKey(), $volumeValues[1]->getKey()])->sort()->values()->all(),
    ], 'The combination is a mixture of two concurrent submissions, so '.
        'SetVariationAttributeValues is no longer replacing the set atomically.'.$report);
});
