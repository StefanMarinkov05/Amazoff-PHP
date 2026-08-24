<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Catalogue\CreateProduct;
use App\Actions\Catalogue\SetVariationImages;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns one catalogue fixture document into rows, through the same Actions
 * the panel calls.
 *
 * `reference/schema/fixture-format.md` is the document shape; ADR-0003 is why the
 * shape is nested documents with slug references rather than table dumps.
 * `fixtures:validate` checks a set *before* any of this runs, so this class
 * assumes a valid document and fails loudly rather than defensively if it is
 * handed a bad one.
 *
 * Slug maps are built once per loader instance, not once per document — a
 * 300-product set otherwise issues 300 identical lookups per referenced
 * table. They are built lazily so a set referencing no attributes never
 * queries `attribute_values` at all.
 *
 * Actor is `null` throughout: seeding is the system acting, not a user, which
 * ADR-0007 permits explicitly for exactly this case. Every Action called here
 * skips its policy check as a result — that is the documented behaviour, not
 * an oversight, and it is why fixtures are trusted input loaded from disk
 * rather than anything reaching this from a request.
 */
final class FixtureLoader
{
    /** @var array<string, int>|null */
    private ?array $categories = null;

    /** @var array<string, int>|null */
    private ?array $brands = null;

    /** @var array<string, int>|null */
    private ?array $attributeValues = null;

    /** @var array<string, int>|null */
    private ?array $attributes = null;

    public function __construct(
        private readonly CreateProduct $createProduct,
        private readonly SetVariationImages $setVariationImages,
    ) {}

    /**
     * @param  array<string, mixed>  $document  One decoded product fixture.
     */
    public function loadProduct(array $document): Product
    {
        $product = $this->createProduct->handle(
            $this->productColumns($document),
            $this->variationRows($document),
            null,
        );

        $imageKeyToId = $this->insertImages($product, $document['images'] ?? []);

        // Read back once and index by SKU. Looking each variation up as it is
        // needed costs two queries per variation — 1,500 round trips on a
        // 300-product set, for rows that were just written in this method.
        /** @var array<string, ProductVariation> $variationsBySku */
        $variationsBySku = $product->productVariations()->get()->keyBy('sku')->all();

        $this->attachGalleries($variationsBySku, $document['variations'], $imageKeyToId);
        $this->insertSpecifications($product, $document['specifications'] ?? []);
        $this->attachAttributes($product, $document['attributes'] ?? []);
        $this->attachAttributeValues($variationsBySku, $document['variations']);

        return $product;
    }

    /**
     * `document['attributes']` names which axes the product varies by — the
     * vocabulary `variations[].attribute_values` draws its keys from. Was
     * read out of the document by `productColumns()`'s `Arr::except()` and
     * then never used anywhere: `attribute_product` stayed empty for every
     * fixture-loaded product, silently, because nothing else in the app
     * reads that pivot either — so no test and no panel screen caught it.
     * A Filament-created product still gets it, through `ProductForm`'s own
     * multiselect syncing `attributes()` directly.
     *
     * @param  list<string>  $attributeSlugs
     */
    private function attachAttributes(Product $product, array $attributeSlugs): void
    {
        if ($attributeSlugs === []) {
            return;
        }

        $ids = array_map(fn (string $slug): int => $this->attributeId($slug), $attributeSlugs);

        $product->attributes()->syncWithoutDetaching($ids);
    }

    /**
     * Product columns, with the fixture's own keys translated.
     *
     * `category`/`brand` are slugs in the fixture and foreign keys in the
     * database — the whole point of ADR-0003's natural-key rule, so the file
     * carries no ID that could be wrong.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function productColumns(array $document): array
    {
        $columns = Arr::except($document, [
            'category', 'brand', 'attributes', 'images', 'specifications', 'variations',
        ]);

        $columns['product_category_id'] = $this->categoryId($document['category']);
        $columns['brand_id'] = isset($document['brand'])
            ? $this->brandId($document['brand'])
            : null;

        foreach (['discount_starts_at', 'discount_ends_at'] as $dateColumn) {
            if (isset($columns[$dateColumn])) {
                $columns[$dateColumn] = $this->resolveRelativeDate($columns[$dateColumn]);
            }
        }

        return $columns;
    }

    /**
     * Variation rows as `CreateProduct` wants them: columns plus
     * `initial_quantity`, which it strips and turns into an opening stock
     * movement. `images` and `attribute_values` are fixture-only keys and are
     * applied afterwards, once there are IDs to attach them to.
     *
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function variationRows(array $document): array
    {
        return array_map(
            static fn (array $variation): array => Arr::except($variation, ['images', 'attribute_values']),
            $document['variations'],
        );
    }

    /**
     * Inserted directly rather than through `AddProductImage`, and this is
     * the one deliberate exception to "everything goes through the Action".
     * That Action promotes the first image to main on every call, so a
     * fixture stating `is_main` on the third image would have it overwritten
     * by the first. The fixture is authoritative here; `fixtures:validate`
     * already enforces at most one `is_main` per document.
     *
     * @param  list<array<string, mixed>>  $images
     * @return array<string, int> Local fixture key to `product_images.id`.
     */
    private function insertImages(Product $product, array $images): array
    {
        $map = [];

        foreach ($images as $image) {
            /** @var ProductImage $row */
            $row = $product->productImages()->create([
                'path' => $image['path'],
                'alt_text' => $image['alt_text'] ?? null,
                'is_main' => (bool) ($image['is_main'] ?? false),
                'sort_order' => (int) ($image['sort_order'] ?? 0),
            ]);

            $map[$image['key']] = (int) $row->getKey();
        }

        return $map;
    }

    /**
     * @param  array<string, ProductVariation>  $variationsBySku
     * @param  list<array<string, mixed>>  $variations
     * @param  array<string, int>  $imageKeyToId
     */
    private function attachGalleries(array $variationsBySku, array $variations, array $imageKeyToId): void
    {
        foreach ($variations as $variation) {
            $keys = $variation['images'] ?? [];

            if ($keys === []) {
                continue;
            }

            $this->setVariationImages->handle(
                $variationsBySku[$variation['sku']],
                array_map(
                    static fn (string $key): int => $imageKeyToId[$key],
                    $keys,
                ),
                null,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $specifications
     */
    private function insertSpecifications(Product $product, array $specifications): void
    {
        foreach ($specifications as $specification) {
            $product->productSpecifications()->create([
                'name' => $specification['name'],
                'value' => $specification['value'],
                'sort_order' => (int) ($specification['sort_order'] ?? 0),
            ]);
        }
    }

    /**
     * `attribute_values` in the fixture is `{attribute_slug: value_slug}`;
     * the pivot wants `attribute_values.id`, so the map is keyed by the
     * compound `attribute_slug/value_slug` — value slugs are unique per
     * attribute, not globally ("red" could belong to Colour and to Finish).
     *
     * @param  array<string, ProductVariation>  $variationsBySku
     * @param  list<array<string, mixed>>  $variations
     */
    private function attachAttributeValues(array $variationsBySku, array $variations): void
    {
        foreach ($variations as $variation) {
            $pairs = $variation['attribute_values'] ?? [];

            if ($pairs === []) {
                continue;
            }

            $ids = [];

            foreach ($pairs as $attributeSlug => $valueSlug) {
                $ids[] = $this->attributeValueId($attributeSlug, $valueSlug);
            }

            $variationsBySku[$variation['sku']]
                ->attributeValues()
                ->syncWithoutDetaching($ids);
        }
    }

    /**
     * Relative offsets rather than absolute dates, per `fixture-format.md`:
     * an absolute "discount ends 2026-09-01" silently stops covering the
     * discount-active branch a month after the fixture is authored, and
     * nothing reports it.
     */
    private function resolveRelativeDate(string $offset): Carbon
    {
        $resolved = Carbon::parse($offset);

        return $resolved;
    }

    private function categoryId(string $slug): int
    {
        $this->categories ??= ProductCategory::query()->pluck('id', 'slug')->all();

        return $this->categories[$slug]
            ?? throw new RuntimeException("Fixture references unknown category slug [{$slug}].");
    }

    private function brandId(string $slug): int
    {
        $this->brands ??= Brand::query()->pluck('id', 'slug')->all();

        return $this->brands[$slug]
            ?? throw new RuntimeException("Fixture references unknown brand slug [{$slug}].");
    }

    private function attributeId(string $slug): int
    {
        $this->attributes ??= Attribute::query()->pluck('id', 'slug')->all();

        return $this->attributes[$slug]
            ?? throw new RuntimeException("Fixture references unknown attribute slug [{$slug}].");
    }

    private function attributeValueId(string $attributeSlug, string $valueSlug): int
    {
        // Query builder rather than Eloquent: the join's aliases are not
        // properties of AttributeValue, and pretending otherwise is what
        // Larastan objects to.
        $this->attributeValues ??= DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->get(['attribute_values.id', 'attribute_values.slug as value_slug', 'attributes.slug as attribute_slug'])
            ->mapWithKeys(static fn (object $row): array => [
                "{$row->attribute_slug}/{$row->value_slug}" => (int) $row->id,
            ])
            ->all();

        $key = "{$attributeSlug}/{$valueSlug}";

        return $this->attributeValues[$key]
            ?? throw new RuntimeException("Fixture references unknown attribute value [{$key}].");
    }

    /**
     * Every attribute slug in the database, for `fixtures:validate` to check
     * a document's `attributes` list against without duplicating the query.
     *
     * @return list<string>
     */
    public function knownAttributeSlugs(): array
    {
        return Attribute::query()->pluck('slug')->all();
    }
}
