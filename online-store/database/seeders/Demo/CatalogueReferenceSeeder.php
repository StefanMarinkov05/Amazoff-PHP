<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\AttributeInputType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

/**
 * The lookup rows a product fixture resolves its slugs against.
 *
 * Fixtures carry `"category": "power-tools"` rather than an integer ID
 * (ADR-0003), which only works if the slug already resolves — so this runs
 * before any fixture load, and `fixtures:validate` checks against exactly
 * these rows.
 *
 * Not gated to non-production the way `UserSeeder` is, and not reference data
 * in the sense `CarrierSeeder` is either: a production catalogue is entered
 * through the panel per ADR-0003, so `DatabaseSeeder` does not call this.
 * `DemoSeeder` and the test fixtures do.
 *
 * ## Why the vocabulary is JSON rather than a const
 *
 * It was a PHP const, two levels deep, because a shallow hardware-store tree
 * fitted in one. A real marketplace taxonomy is ~100 nodes and three or four
 * levels (`clothing > men > tops > t-shirts`), which the old
 * `slug => [name, children: slug => name]` shape could not express at all —
 * its children were strings, so they could not carry children of their own.
 *
 * Moving it to `database/fixtures/reference/catalogue.json` makes the
 * taxonomy authorable as data rather than code: the thing that most wants
 * regenerating in bulk is the thing least suited to being a PHP literal, and
 * a generated `.json` cannot introduce a syntax error into `database/`.
 * Nesting is now arbitrary-depth — `seedCategories()` recurses.
 *
 * `updateOrCreate` on the slug throughout: re-running must not duplicate, and
 * the slug is the natural key the fixtures already use. Note that this
 * **adds and updates but never deletes** — a category removed from the JSON
 * keeps its row, because deleting one with products attached is
 * `DeleteProductCategory`'s guarded decision, not a seeder's side effect.
 */
class CatalogueReferenceSeeder extends Seeder
{
    private const VOCABULARY = 'database/fixtures/reference/catalogue.json';

    public function run(): void
    {
        $vocabulary = $this->vocabulary();

        $this->seedCategories($vocabulary['categories'], null);
        $this->seedBrands($vocabulary['brands']);
        $this->seedAttributes($vocabulary['attributes']);
    }

    /**
     * @return array{categories: list<array<string, mixed>>, brands: array<string, string>, attributes: array<string, array<string, mixed>>}
     */
    private function vocabulary(): array
    {
        $path = base_path(self::VOCABULARY);

        if (! file_exists($path)) {
            throw new RuntimeException(
                self::VOCABULARY.' is missing — it is the vocabulary every product fixture resolves its slugs against.'
            );
        }

        try {
            // Deliberately typed loose here rather than as the shape this
            // returns: the file is untrusted input, so the key check below is
            // a real runtime guard. Annotating the narrow shape up front would
            // make Larastan treat that guard as dead code and report it.
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(self::VOCABULARY.' is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        foreach (['categories', 'brands', 'attributes'] as $key) {
            if (! isset($decoded[$key])) {
                throw new RuntimeException(self::VOCABULARY." is missing the [{$key}] key.");
            }
        }

        /** @var array{categories: list<array<string, mixed>>, brands: array<string, string>, attributes: array<string, array<string, mixed>>} $decoded */
        return $decoded;
    }

    /**
     * Depth is whatever the document nests to. `parent_id` is self-referencing
     * and unconstrained in depth, and nothing downstream cares: `FixtureLoader`
     * resolves a product's `category` against any row, and `fixtures:validate`
     * plucks every slug regardless of where it sits in the tree.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function seedCategories(array $categories, ?int $parentId): void
    {
        foreach ($categories as $category) {
            foreach (['slug', 'name'] as $required) {
                if (! isset($category[$required])) {
                    throw new RuntimeException(
                        self::VOCABULARY.": a category is missing [{$required}]."
                    );
                }
            }

            $model = ProductCategory::updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'parent_id' => $parentId],
            );

            /** @var list<array<string, mixed>> $children */
            $children = $category['children'] ?? [];

            if ($children !== []) {
                $this->seedCategories($children, (int) $model->getKey());
            }
        }
    }

    /**
     * @param  array<string, string>  $brands
     */
    private function seedBrands(array $brands): void
    {
        foreach ($brands as $slug => $name) {
            Brand::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }

    /**
     * `colour` carries hex codes because `AttributeInputType::Color` renders a
     * swatch from `color_hex`; a plain select leaves it null.
     *
     * Value slugs are unique per attribute (`UNIQUE(attribute_id, slug)`), not
     * globally — two attributes may both own an `s`.
     *
     * An optional `categories` list scopes the attribute to those master
     * categories via `attribute_product_category`; descendants inherit it
     * (`ResolveAllowedAttributes`), so only the top of each branch is named.
     * Omitting the key leaves the attribute unrestricted, which is that
     * table's documented default.
     *
     * @param  array<string, array<string, mixed>>  $attributes
     */
    private function seedAttributes(array $attributes): void
    {
        $sortOrder = 0;

        foreach ($attributes as $slug => $attribute) {
            $type = AttributeInputType::tryFrom((string) ($attribute['type'] ?? ''));

            if ($type === null) {
                throw new RuntimeException(
                    self::VOCABULARY.": attribute [{$slug}] has an unknown input type [".
                    (string) ($attribute['type'] ?? '').']. Known: '.
                    implode(', ', array_column(AttributeInputType::cases(), 'value')).'.'
                );
            }

            $model = Attribute::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $attribute['name'] ?? ucfirst(str_replace('-', ' ', $slug)),
                    'input_type' => $type,
                    'is_filterable' => (bool) ($attribute['filterable'] ?? false),
                    'is_variation_only' => (bool) ($attribute['variation_only'] ?? false),
                    'sort_order' => $sortOrder++,
                ],
            );

            $this->scopeAttributeToCategories($model, $attribute['categories'] ?? [], $slug);

            $valueOrder = 0;

            /** @var array<string, array<string, mixed>> $values */
            $values = $attribute['values'] ?? [];

            foreach ($values as $valueSlug => $value) {
                AttributeValue::updateOrCreate(
                    ['slug' => $valueSlug, 'attribute_id' => $model->getKey()],
                    [
                        'value' => $value['label'] ?? $valueSlug,
                        'color_hex' => $value['hex'] ?? null,
                        'sort_order' => $valueOrder++,
                    ],
                );
            }
        }
    }

    /**
     * `sync`, not `syncWithoutDetaching`: the vocabulary file is the whole
     * truth for an attribute's scope, so a category removed from the list
     * has to lose the row on a re-seed rather than keep it forever — the
     * same reset-then-assign reasoning `DemoShowcaseOrderSeeder` records.
     *
     * An unknown slug fails loudly. A silently-skipped one would leave the
     * attribute wrongly unrestricted, which is indistinguishable from
     * "deliberately global" once seeding has finished.
     *
     * @param  list<string>  $categorySlugs
     */
    private function scopeAttributeToCategories(Attribute $attribute, array $categorySlugs, string $attributeSlug): void
    {
        $ids = [];

        foreach ($categorySlugs as $categorySlug) {
            $id = ProductCategory::query()->where('slug', $categorySlug)->value('id');

            if ($id === null) {
                throw new RuntimeException(
                    self::VOCABULARY.": attribute [{$attributeSlug}] is scoped to an unknown category [{$categorySlug}]."
                );
            }

            $ids[] = $id;
        }

        $attribute->productCategories()->sync($ids);
    }
}
