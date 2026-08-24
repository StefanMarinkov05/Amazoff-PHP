<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ProductCategory;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Checks a fixture set before anything writes a row.
 *
 * ADR-0003's reasoning for validating separately rather than relying on the
 * database: the database *would* catch most of this, but one failure at a
 * time, mid-transaction, after thousands of rows are already written. This
 * reports every failure in the set at once, with a path to each, and exits
 * non-zero.
 *
 * Three layers, per the ADR:
 *
 * 1. Document shape — required keys, correct types.
 * 2. Column constraints — derived from the schema rather than restated, so a
 *    column that changes width does not leave a stale number here.
 * 3. Cross-document invariants the database cannot express — SKU uniqueness
 *    across *both* `products` and `product_variations`, slug resolvability,
 *    `discount_price < regular_price`, at least one variation per product,
 *    at most one main image, gallery keys resolving locally.
 *
 * Layer 3 is the one that earns the command. The others are conveniences.
 */
final class ValidateFixtures extends Command
{
    protected $signature = 'fixtures:validate {path=database/fixtures/demo : Directory of JSON fixture documents}';

    protected $description = 'Validate catalogue fixture documents before seeding';

    /** @var list<string> */
    private array $failures = [];

    /** @var array<string, string> Every SKU seen, to its source path. */
    private array $skus = [];

    /** @var array<string, string> Every product slug seen, to its source path. */
    private array $slugs = [];

    public function handle(): int
    {
        $directory = base_path((string) $this->argument('path'));

        if (! is_dir($directory)) {
            $this->error("No such directory: {$directory}");

            return self::FAILURE;
        }

        $files = glob($directory.'/*.json') ?: [];

        if ($files === []) {
            $this->error("No .json documents in {$directory}");

            return self::FAILURE;
        }

        $categorySlugs = ProductCategory::query()->pluck('slug')->all();
        $brandSlugs = Brand::query()->pluck('slug')->all();
        $attributeValueKeys = $this->attributeValueKeys();

        foreach ($files as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            try {
                // Either a single product object or a batch array of them,
                // so array-key rather than string — array_is_list() below is
                // what tells the two apart.
                /** @var array<array-key, mixed> $decoded */
                $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->recordFailure($relative, 'is not valid JSON: '.$e->getMessage());

                continue;
            }

            // A file holds either one product or a batch of them. Both are
            // accepted so a generated batch loads without a manual splitting
            // step; `array_is_list` is what separates the two, since a single
            // document is always a string-keyed object.
            // A batch reports its offset so a failure in the eleventh
            // product reads as `power-tools.json[10]`; a single-document
            // file has no meaningful index to add.
            $isBatch = array_is_list($decoded);

            foreach ($this->documentsIn($decoded) as $index => $document) {
                $path = $isBatch ? "{$relative}[{$index}]" : $relative;

                $this->validateDocument($path, $document, $categorySlugs, $brandSlugs, $attributeValueKeys);
            }
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $this->line("<fg=red>✗</> {$failure}");
            }

            $this->newLine();
            $this->error(count($this->failures).' problem(s) across '.count($files).' document(s).');

            return self::FAILURE;
        }

        $this->info(count($files).' document(s) valid.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $categorySlugs
     * @param  list<string>  $brandSlugs
     * @param  list<string>  $attributeValueKeys
     */
    private function validateDocument(
        string $path,
        array $document,
        array $categorySlugs,
        array $brandSlugs,
        array $attributeValueKeys,
    ): void {
        foreach (['name', 'slug', 'sku', 'category', 'regular_price', 'variations'] as $required) {
            if (! isset($document[$required])) {
                $this->recordFailure($path, "is missing required key [{$required}]");

                return;
            }
        }

        $this->assertUniqueSlug($path, (string) $document['slug']);
        $this->assertUniqueSku($path, (string) $document['sku'], 'product');
        $this->assertLengths($path, $document);

        if (! in_array($document['category'], $categorySlugs, true)) {
            $this->recordFailure($path, "references unknown category slug [{$document['category']}]");
        }

        if (isset($document['brand']) && ! in_array($document['brand'], $brandSlugs, true)) {
            $this->recordFailure($path, "references unknown brand slug [{$document['brand']}]");
        }

        $this->assertPrices($path, $document);
        $imageKeys = $this->assertImages($path, $document);
        $this->assertVariations($path, $document, $imageKeys, $attributeValueKeys);
    }

    /**
     * SKELETON.md rule 10 promises these are validator-checked; until this
     * method existed, nothing checked them — an overlength `name` reached
     * `CreateProduct` and failed as a raw `QueryException` (1406, "Data too
     * long for column"), inside `DemoSeeder`'s single transaction, discarding
     * every product that had already loaded in the same run. The validator's
     * whole purpose is catching exactly this before a row is written.
     *
     * Limits match each column's own `string(n)` definition in
     * `create_products_table` — kept here rather than introspected from the
     * schema, the same tradeoff `assertPrices()` and `assertImages()` already
     * make: a hardcoded number that could drift from a migration versus a
     * schema query on every validate run. `mb_strlen`, not `strlen`: a
     * multi-byte character (the em dash used for coverage's ≥90-char name
     * case) is one column character but several bytes, and `strlen` would
     * flag a name MySQL accepts.
     *
     * @param  array<string, mixed>  $document
     */
    private function assertLengths(string $path, array $document): void
    {
        $this->assertMaxLength($path, 'name', (string) $document['name'], 100);
        $this->assertMaxLength($path, 'slug', (string) $document['slug'], 100);
        $this->assertMaxLength($path, 'sku', (string) $document['sku'], 64);

        if (isset($document['short_description'])) {
            $this->assertMaxLength($path, 'short_description', (string) $document['short_description'], 255);
        }

        if (isset($document['seo_title'])) {
            $this->assertMaxLength($path, 'seo_title', (string) $document['seo_title'], 100);
        }

        if (isset($document['seo_description'])) {
            $this->assertMaxLength($path, 'seo_description', (string) $document['seo_description'], 255);
        }

        foreach ($document['variations'] ?? [] as $index => $variation) {
            if (isset($variation['sku'])) {
                $this->assertMaxLength(
                    "{$path}.variations[{$index}]",
                    'sku',
                    (string) $variation['sku'],
                    64,
                );
            }
        }
    }

    private function assertMaxLength(string $path, string $field, string $value, int $max): void
    {
        $length = mb_strlen($value);

        if ($length > $max) {
            $this->recordFailure(
                $path,
                "[{$field}] is {$length} characters, over the {$max}-character column limit"
            );
        }
    }

    /**
     * Money is a string in the fixture because JSON numbers are floats and
     * CLAUDE.md forbids float for money. That makes the comparison a string
     * comparison, so it goes through `Money::isLessThan()` rather than `<`.
     *
     * @param  array<string, mixed>  $document
     */
    private function assertPrices(string $path, array $document): void
    {
        $regular = (string) $document['regular_price'];

        if (! is_numeric($regular)) {
            $this->recordFailure($path, "has a non-numeric regular_price [{$regular}]");

            return;
        }

        if (! isset($document['discount_price'])) {
            return;
        }

        $discount = (string) $document['discount_price'];

        if (! is_numeric($discount)) {
            $this->recordFailure($path, "has a non-numeric discount_price [{$discount}]");

            return;
        }

        if (! Money::of($discount)->isLessThan(Money::of($regular))) {
            $this->recordFailure($path, "has discount_price [{$discount}] >= regular_price [{$regular}]");
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string> The document's local image keys.
     */
    private function assertImages(string $path, array $document): array
    {
        $images = $document['images'] ?? [];
        $keys = [];
        $mainCount = 0;

        foreach ($images as $index => $image) {
            if (! isset($image['key'], $image['path'])) {
                $this->recordFailure($path, "images[{$index}] is missing [key] or [path]");

                continue;
            }

            if (in_array($image['key'], $keys, true)) {
                $this->recordFailure($path, "images[{$index}] repeats the local key [{$image['key']}]");
            }

            $keys[] = $image['key'];
            $mainCount += ($image['is_main'] ?? false) ? 1 : 0;
        }

        if ($mainCount > 1) {
            $this->recordFailure($path, "marks {$mainCount} images as is_main; at most one is allowed");
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $imageKeys
     * @param  list<string>  $attributeValueKeys
     */
    private function assertVariations(
        string $path,
        array $document,
        array $imageKeys,
        array $attributeValueKeys,
    ): void {
        $variations = $document['variations'];

        if ($variations === []) {
            $this->recordFailure($path, 'has no variations; CreateProduct requires at least one');

            return;
        }

        $seenCombinations = [];

        foreach ($variations as $index => $variation) {
            $label = "variations[{$index}]";

            if (! isset($variation['sku'])) {
                $this->recordFailure($path, "{$label} is missing [sku]");

                continue;
            }

            $this->assertUniqueSku($path, (string) $variation['sku'], $label);

            foreach ($variation['images'] ?? [] as $key) {
                if (! in_array($key, $imageKeys, true)) {
                    $this->recordFailure($path, "{$label} references image key [{$key}], which this document does not define");
                }
            }

            foreach ($variation['attribute_values'] ?? [] as $attributeSlug => $valueSlug) {
                $compound = "{$attributeSlug}/{$valueSlug}";

                if (! in_array($compound, $attributeValueKeys, true)) {
                    $this->recordFailure($path, "{$label} references unknown attribute value [{$compound}]");
                }
            }

            // Two variations of one product carrying identical attribute
            // values are indistinguishable to a customer choosing between
            // them, and no database constraint can express it (ADR-0005 lists
            // this among the invariants that stay in application code).
            $combination = json_encode($variation['attribute_values'] ?? []);

            if ($combination !== '[]' && in_array($combination, $seenCombinations, true)) {
                $this->recordFailure($path, "{$label} repeats an attribute-value combination already used in this document");
            }

            $seenCombinations[] = $combination;
        }
    }

    private function assertUniqueSku(string $path, string $sku, string $label): void
    {
        if (isset($this->skus[$sku])) {
            $this->recordFailure($path, "{$label} SKU [{$sku}] already used in {$this->skus[$sku]}");

            return;
        }

        $this->skus[$sku] = $path;
    }

    private function assertUniqueSlug(string $path, string $slug): void
    {
        if (isset($this->slugs[$slug])) {
            $this->recordFailure($path, "slug [{$slug}] already used in {$this->slugs[$slug]}");

            return;
        }

        $this->slugs[$slug] = $path;
    }

    /**
     * One decoded file as a list of documents, whether it held a single
     * product object or a batch array of them.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function documentsIn(array $decoded): array
    {
        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    /**
     * @return list<string> `attribute_slug/value_slug` for every value.
     */
    private function attributeValueKeys(): array
    {
        // Query builder, not Eloquent: the aliases below are join output,
        // not properties of AttributeValue.
        return DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attribute_values.slug as value_slug', 'attributes.slug as attribute_slug'])
            ->map(static fn (object $row): string => "{$row->attribute_slug}/{$row->value_slug}")
            ->all();
    }

    private function recordFailure(string $path, string $problem): void
    {
        $this->failures[] = "{$path} {$problem}";
    }
}
