<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\CreateProduct;
use App\Enums\ArticleStatus;
use App\Enums\AttributeInputType;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use App\Support\PlaceholderImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The demo catalogue: a shop that looks like a shop.
 *
 * ADR-0003 splits seeding three ways — `DatabaseSeeder` is small and
 * deterministic for CI, this is the demo, `StressSeeder` would be volume.
 * Never runs in CI and never in production, where §37 says catalogue content
 * is entered through the panel.
 *
 * **Deviation from ADR-0003, stated rather than hidden:** the ADR specifies
 * committed JSON fixtures with a `fixtures:validate` gate. The prose here is
 * inline PHP instead. The ADR's reasoning holds — prose authored once and
 * reviewed, numbers and states derived by rule — but the fixture format and
 * its validator do not exist yet, and inventing half of one here would be
 * worse than a clearly-marked interim. The state coverage below is the part
 * of the ADR that actually matters for §37 and it is honoured in full.
 *
 * **State diversity is the point, not volume.** ADR-0003: what a demo has to
 * show is not 500 plausible products but a catalogue covering every branch of
 * the UI. Deliberately present:
 *   - discount active, discount expired, discount scheduled, no discount
 *   - one variation, several variations
 *   - out of stock, one left, deep stock
 *   - a product with no image at all
 *   - a name long enough to test a card layout
 *   - unavailable products, which the storefront must never show
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->command?->info('Clearing existing catalogue content (users are kept)…');
        $this->purge();

        $author = User::query()->where('email', 'editor@example.com')->first()
            ?? User::query()->firstOrFail();

        $brands = $this->brands();
        $categories = $this->categories();
        $attributes = $this->attributes();

        $this->command?->info('Seeding products…');
        $this->products($brands, $categories, $attributes);

        $this->command?->info('Seeding content…');
        $this->content($author);

        $this->command?->info('Seeding reviews…');
        $this->reviews();

        $this->command?->info('Seeding coupons…');
        $this->coupons();

        $this->summarise();
    }

    /**
     * Catalogue content only. Users, roles, permissions and the order history
     * they own are left alone — an order references products, so wiping the
     * catalogue under a live order would orphan `order_items.product_id`
     * (nullable by design, but silently losing the link is not a "reset").
     */
    private function purge(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'article_product', 'article_tag', 'articles', 'tags', 'article_categories',
            'coupon_product', 'coupon_product_category', 'coupon_redemptions', 'coupons',
            'attribute_value_product_variation', 'attribute_product',
            'inventory_movements', 'inventories',
            'product_specifications', 'product_images', 'product_reviews',
            'cart_items', 'carts', 'wishlist_items',
            'product_variations', 'products',
            'attribute_values', 'attributes',
            'product_categories', 'brands',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $names = [
            'Aurelia Audio', 'Northwind Tools', 'Meridian Optics', 'Kestrel Outdoor',
            'Palladium Kitchen', 'Vector Peripherals', 'Halcyon Home', 'Ridgeline Sport',
            'Lumen Studio', 'Ferrous Workshop',
        ];

        $brands = [];

        foreach ($names as $name) {
            $brands[$name] = Brand::create(['name' => $name, 'slug' => Str::slug($name)]);
        }

        return $brands;
    }

    /** @return array<string, ProductCategory> */
    private function categories(): array
    {
        $tree = [
            'Audio' => ['Headphones', 'Speakers', 'Turntables'],
            'Computing' => ['Keyboards', 'Mice & Trackpads', 'Monitors'],
            'Home & Kitchen' => ['Cookware', 'Coffee & Tea', 'Lighting'],
            'Outdoor' => ['Backpacks', 'Camping', 'Navigation'],
        ];

        $categories = [];

        foreach ($tree as $parentName => $children) {
            $parent = ProductCategory::create([
                'name' => $parentName,
                'slug' => Str::slug($parentName),
                'description' => "Everything in {$parentName}.",
            ]);
            $categories[$parentName] = $parent;

            foreach ($children as $childName) {
                $categories[$childName] = ProductCategory::create([
                    'parent_id' => $parent->id,
                    'name' => $childName,
                    'slug' => Str::slug($childName),
                    'description' => "{$childName} in {$parentName}.",
                ]);
            }
        }

        return $categories;
    }

    /** @return array<string, array<string, AttributeValue>> */
    private function attributes(): array
    {
        $definitions = [
            'Colour' => [AttributeInputType::Color, [
                'Graphite' => '#3f3f46', 'Ivory' => '#f5f1e8', 'Marine' => '#1d4ed8',
                'Moss' => '#4d7c37', 'Clay' => '#b45309',
            ]],
            'Size' => [AttributeInputType::Select, ['S' => null, 'M' => null, 'L' => null, 'XL' => null]],
            'Capacity' => [AttributeInputType::Select, ['20 L' => null, '35 L' => null, '55 L' => null]],
            'Switch type' => [AttributeInputType::Select, ['Linear' => null, 'Tactile' => null, 'Silent' => null]],
        ];

        $result = [];
        $sort = 0;

        foreach ($definitions as $name => [$inputType, $values]) {
            $attribute = Attribute::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'input_type' => $inputType,
                'is_filterable' => true,
                'sort_order' => $sort++,
            ]);

            $valueSort = 0;

            foreach ($values as $value => $hex) {
                $result[$name][$value] = AttributeValue::create([
                    'attribute_id' => $attribute->id,
                    'value' => $value,
                    'slug' => Str::slug($value),
                    'color_hex' => $hex,
                    'sort_order' => $valueSort++,
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, Brand>  $brands
     * @param  array<string, ProductCategory>  $categories
     * @param  array<string, array<string, AttributeValue>>  $attributes
     */
    private function products(array $brands, array $categories, array $attributes): void
    {
        $createProduct = app(CreateProduct::class);
        $addVariation = app(AddProductVariation::class);

        foreach ($this->productDefinitions() as $definition) {
            /** @var Product $product */
            $product = $createProduct->handle(
                [
                    'product_category_id' => $categories[$definition['category']]->id,
                    'brand_id' => $brands[$definition['brand']]->id,
                    'name' => $definition['name'],
                    'slug' => Str::slug($definition['name']),
                    'sku' => $definition['sku'],
                    'short_description' => $definition['short'],
                    'description' => $definition['description'],
                    'regular_price' => $definition['price'],
                    'discount_price' => $definition['discount'] ?? null,
                    'discount_starts_at' => $definition['discount_from'] ?? null,
                    'discount_ends_at' => $definition['discount_to'] ?? null,
                    'vat_rate' => '20.00',
                    'min_order_quantity' => 1,
                    'weight' => $definition['weight'] ?? null,
                    'is_available' => $definition['available'] ?? true,
                    'is_featured' => $definition['featured'] ?? false,
                    'seo_title' => $definition['name'],
                    'seo_description' => $definition['short'],
                ],
                // CreateProduct refuses a product with no variation, so the
                // first one is created with it and the rest are added after.
                [array_merge($definition['variations'][0], ['sku' => $definition['sku'].'-1'])],
                null,
            );

            foreach (array_slice($definition['variations'], 1) as $index => $variation) {
                $addVariation->handle(
                    $product,
                    array_diff_key(
                        array_merge($variation, ['sku' => $definition['sku'].'-'.($index + 2)]),
                        ['initial_quantity' => null],
                    ),
                    (int) ($variation['initial_quantity'] ?? 0),
                    null,
                );
            }

            $this->attachAttributes($product, $definition, $attributes);
            $this->images($product, $definition);
            $this->specifications($product, $definition);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, AttributeValue>>  $attributes
     */
    private function attachAttributes(Product $product, array $definition, array $attributes): void
    {
        foreach ($definition['attributes'] ?? [] as $attributeName) {
            $attribute = Attribute::query()->where('name', $attributeName)->first();

            if ($attribute !== null) {
                $product->attributes()->syncWithoutDetaching([$attribute->id]);
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function images(Product $product, array $definition): void
    {
        // One product deliberately has none — the catalogue's "no image"
        // branch has to be visible in the demo, not only in tests.
        if (($definition['images'] ?? 1) === 0) {
            return;
        }

        foreach (range(1, $definition['images'] ?? 1) as $position) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => PlaceholderImage::render(
                    $product->name.($position > 1 ? " view {$position}" : ''),
                    $definition['category'],
                ),
                'alt_text' => $product->name,
                'is_main' => $position === 1,
                'sort_order' => $position - 1,
            ]);
        }
    }

    /** @param array<string, mixed> $definition */
    private function specifications(Product $product, array $definition): void
    {
        $sort = 0;

        foreach ($definition['specs'] ?? [] as $name => $value) {
            ProductSpecification::create([
                'product_id' => $product->id,
                'name' => $name,
                'value' => $value,
                'sort_order' => $sort++,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function productDefinitions(): array
    {
        $now = Carbon::now();

        return [
            [
                'name' => 'Aurelia One Over-Ear Headphones',
                'sku' => 'AUR-ONE', 'brand' => 'Aurelia Audio', 'category' => 'Headphones',
                'short' => 'Closed-back studio headphones with a replaceable cable.',
                'description' => 'A closed-back reference pair built for long sessions. The earcups swivel flat, the headband is replaceable, and the 3.5 mm cable detaches from both sides so a failed cable never means a failed pair of headphones.',
                'price' => '289.00', 'discount' => '229.00',
                'discount_from' => $now->copy()->subDays(3), 'discount_to' => $now->copy()->addDays(11),
                'weight' => '0.34', 'featured' => true, 'images' => 3,
                'attributes' => ['Colour'],
                'specs' => ['Driver' => '40 mm dynamic', 'Impedance' => '32 Ω', 'Cable' => '1.8 m detachable', 'Warranty' => '2 years'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 24],
                    ['price' => '299.00', 'is_available' => true, 'initial_quantity' => 8],
                ],
            ],
            [
                'name' => 'Aurelia Field Bluetooth Speaker',
                'sku' => 'AUR-FIELD', 'brand' => 'Aurelia Audio', 'category' => 'Speakers',
                'short' => 'A palm-sized speaker rated IP67 for dust and water.',
                'description' => 'Sealed against dust and submersion, with a braided lanyard and eighteen hours of playback. Pairs two units into a stereo pair.',
                'price' => '119.00', 'weight' => '0.62', 'images' => 2,
                'attributes' => ['Colour'],
                'specs' => ['Battery' => '18 hours', 'Rating' => 'IP67', 'Bluetooth' => '5.3'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 41],
                ],
            ],
            [
                'name' => 'Meridian Verso Turntable',
                'sku' => 'MER-VERSO', 'brand' => 'Meridian Optics', 'category' => 'Turntables',
                'short' => 'Belt-driven turntable with a pre-mounted cartridge.',
                'description' => 'A belt-driven deck with an aluminium platter and a pre-mounted moving-magnet cartridge, so it plays out of the box without an alignment protractor.',
                'price' => '549.00', 'weight' => '5.10', 'featured' => true, 'images' => 2,
                'specs' => ['Drive' => 'Belt', 'Speeds' => '33⅓ / 45 rpm', 'Cartridge' => 'MM, pre-mounted'],
                // One left: the storefront's low-stock branch.
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 1],
                ],
            ],
            [
                'name' => 'Vector 68 Mechanical Keyboard',
                'sku' => 'VEC-68', 'brand' => 'Vector Peripherals', 'category' => 'Keyboards',
                'short' => 'A 65% hot-swap board with a gasket mount.',
                'description' => 'Sixty-eight keys, gasket-mounted, hot-swappable. Ships with PBT double-shot caps and a coiled USB-C cable.',
                'price' => '169.00', 'discount' => '139.00',
                'discount_from' => $now->copy()->subDays(30), 'discount_to' => $now->copy()->subDays(2),
                'weight' => '0.86', 'images' => 2,
                'attributes' => ['Switch type', 'Colour'],
                'specs' => ['Layout' => '65%', 'Mount' => 'Gasket', 'Keycaps' => 'PBT double-shot'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 15],
                    ['price' => '179.00', 'is_available' => true, 'initial_quantity' => 6],
                    ['price' => '179.00', 'is_available' => true, 'initial_quantity' => 0],
                ],
            ],
            [
                'name' => 'Vector Glide Wireless Mouse',
                'sku' => 'VEC-GLIDE', 'brand' => 'Vector Peripherals', 'category' => 'Mice & Trackpads',
                'short' => 'A 58 g wireless mouse with PTFE feet.',
                'description' => 'Fifty-eight grams, a 26 000 DPI sensor, and replaceable PTFE feet. Charges over USB-C and reports at 1 000 Hz wirelessly.',
                'price' => '89.00', 'weight' => '0.058', 'images' => 1,
                'attributes' => ['Colour'],
                'specs' => ['Weight' => '58 g', 'Sensor' => '26 000 DPI', 'Polling' => '1 000 Hz'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 33],
                ],
            ],
            [
                'name' => 'Meridian 27" Colour-Calibrated Monitor',
                'sku' => 'MER-27C', 'brand' => 'Meridian Optics', 'category' => 'Monitors',
                'short' => 'A 4K IPS panel calibrated at the factory.',
                'description' => 'Ships with a per-unit calibration report. 4K, 99% sRGB, and a stand that pivots to portrait.',
                'price' => '739.00', 'weight' => '6.40', 'images' => 2,
                'specs' => ['Panel' => 'IPS', 'Resolution' => '3840 × 2160', 'Coverage' => '99% sRGB'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 12],
                ],
            ],
            [
                'name' => 'Palladium Seven-Ply Sauté Pan',
                'sku' => 'PAL-SAUTE', 'brand' => 'Palladium Kitchen', 'category' => 'Cookware',
                'short' => 'A seven-ply stainless pan, induction ready.',
                'description' => 'Seven bonded layers to the rim, so the sides heat as evenly as the base. Oven safe to 260 °C and induction ready.',
                'price' => '215.00', 'weight' => '1.95', 'images' => 2,
                'attributes' => ['Size'],
                'specs' => ['Construction' => 'Seven-ply bonded', 'Oven safe' => '260 °C', 'Induction' => 'Yes'],
                'variations' => [
                    ['price' => '215.00', 'is_available' => true, 'initial_quantity' => 9],
                    ['price' => '265.00', 'is_available' => true, 'initial_quantity' => 4],
                ],
            ],
            [
                'name' => 'Palladium Pour-Over Kettle',
                'sku' => 'PAL-KETTLE', 'brand' => 'Palladium Kitchen', 'category' => 'Coffee & Tea',
                'short' => 'A gooseneck kettle with one-degree temperature control.',
                'description' => 'A counterweighted gooseneck for a slow pour, with temperature set to the degree and a thirty-minute hold.',
                'price' => '149.00', 'discount' => '119.00',
                // Scheduled: starts next week, so the storefront must NOT show it yet.
                'discount_from' => $now->copy()->addDays(7), 'discount_to' => $now->copy()->addDays(21),
                'weight' => '1.20', 'images' => 1,
                'specs' => ['Capacity' => '0.9 L', 'Control' => '±1 °C', 'Hold' => '30 minutes'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 18],
                ],
            ],
            [
                'name' => 'Halcyon Arc Table Lamp',
                'sku' => 'HAL-ARC', 'brand' => 'Halcyon Home', 'category' => 'Lighting',
                'short' => 'A dimmable table lamp with a solid brass arm.',
                'description' => 'A weighted base, a solid brass arm that holds its angle, and a stepless dimmer from candlelight to full reading brightness.',
                'price' => '179.00', 'weight' => '2.30', 'featured' => true, 'images' => 2,
                'attributes' => ['Colour'],
                'specs' => ['Material' => 'Solid brass', 'Dimming' => 'Stepless', 'Bulb' => 'E27, not included'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 21],
                ],
            ],
            [
                'name' => 'Kestrel Ridge 35 Backpack',
                'sku' => 'KES-R35', 'brand' => 'Kestrel Outdoor', 'category' => 'Backpacks',
                'short' => 'A 35-litre pack with a floating lid.',
                'description' => 'Thirty-five litres with a floating lid that takes another eight, a framesheet that carries load without a full frame, and recycled ripstop throughout.',
                'price' => '199.00', 'weight' => '1.10', 'images' => 3,
                'attributes' => ['Capacity', 'Colour'],
                'specs' => ['Volume' => '35 L + 8 L lid', 'Fabric' => 'Recycled ripstop', 'Frame' => 'Framesheet'],
                'variations' => [
                    ['price' => '179.00', 'is_available' => true, 'initial_quantity' => 14],
                    ['price' => '199.00', 'is_available' => true, 'initial_quantity' => 7],
                    ['price' => '219.00', 'is_available' => true, 'initial_quantity' => 3],
                ],
            ],
            [
                'name' => 'Kestrel Two-Person Three-Season Tent',
                'sku' => 'KES-T2', 'brand' => 'Kestrel Outdoor', 'category' => 'Camping',
                'short' => 'A freestanding two-person tent under two kilos.',
                'description' => 'Freestanding, two doors, two vestibules, and a 1.9 kg packed weight. Colour-coded poles so pitching in the dark is possible.',
                'price' => '429.00', 'weight' => '1.90', 'images' => 2,
                'specs' => ['Capacity' => 'Two person', 'Packed weight' => '1.9 kg', 'Seasons' => 'Three'],
                // Out of stock everywhere.
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 0],
                ],
            ],
            [
                'name' => 'Northwind Precision Screwdriver Set with Sixty Interchangeable Bits and Magnetic Case',
                'sku' => 'NOR-PREC60', 'brand' => 'Northwind Tools', 'category' => 'Navigation',
                'short' => 'Sixty bits in a magnetic case — a deliberately long product name.',
                'description' => 'Sixty S2 steel bits in a magnetic case, with a knurled aluminium driver and a rotating end cap. The name is long on purpose: a demo catalogue should prove the card layout survives one.',
                'price' => '79.00', 'weight' => '0.48', 'images' => 1,
                'specs' => ['Bits' => '60', 'Steel' => 'S2', 'Case' => 'Magnetic'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 52],
                ],
            ],
            [
                'name' => 'Lumen Studio Softbox Kit',
                'sku' => 'LUM-SOFT', 'brand' => 'Lumen Studio', 'category' => 'Lighting',
                'short' => 'A two-head softbox kit — no photography yet, so no image.',
                'description' => 'Two heads, two stands, two softboxes, one bag. Deliberately seeded without an image so the catalogue\'s no-image branch is visible in the demo.',
                'price' => '259.00', 'weight' => '4.80',
                'images' => 0,
                'specs' => ['Heads' => 'Two', 'Mount' => 'Bowens', 'Bag' => 'Included'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 11],
                ],
            ],
            [
                'name' => 'Ferrous Workshop Bench Vice',
                'sku' => 'FER-VICE', 'brand' => 'Ferrous Workshop', 'category' => 'Navigation',
                'short' => 'Discontinued — unavailable, so the storefront must hide it.',
                'description' => 'A 125 mm cast-steel vice. Marked unavailable on purpose: the catalogue query filters on is_available, and the demo should prove it.',
                'price' => '139.00', 'weight' => '8.20',
                'available' => false, 'images' => 1,
                'specs' => ['Jaw width' => '125 mm', 'Material' => 'Cast steel'],
                'variations' => [
                    ['price' => null, 'is_available' => false, 'initial_quantity' => 0],
                ],
            ],
            [
                'name' => 'Ridgeline Trail Running Vest',
                'sku' => 'RID-VEST', 'brand' => 'Ridgeline Sport', 'category' => 'Camping',
                'short' => 'A 12-litre vest with soft flasks included.',
                'description' => 'Twelve litres across front and rear pockets, two 500 ml soft flasks included, and a bounce-free fit through four adjustment points.',
                'price' => '129.00', 'discount' => '99.00',
                'discount_from' => $now->copy()->subDays(1), 'discount_to' => $now->copy()->addDays(6),
                'weight' => '0.28', 'images' => 2,
                'attributes' => ['Size', 'Colour'],
                'specs' => ['Volume' => '12 L', 'Flasks' => '2 × 500 ml included'],
                'variations' => [
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 19],
                    ['price' => null, 'is_available' => true, 'initial_quantity' => 2],
                ],
            ],
        ];
    }

    private function content(User $author): void
    {
        $categories = [];

        foreach (['Guides', 'Product news', 'Comparisons', 'Workshop'] as $name) {
            $categories[$name] = ArticleCategory::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => "{$name} from the team.",
            ]);
        }

        $tags = [];

        foreach (['audio', 'buying-guide', 'outdoor', 'workspace', 'maintenance', 'coffee'] as $tag) {
            $tags[$tag] = Tag::create(['name' => $tag, 'slug' => $tag]);
        }

        $articles = [
            ['Choosing your first pair of studio headphones', 'Guides', ArticleStatus::Published, ['audio', 'buying-guide'], -21],
            ['Why gasket mounting changed mechanical keyboards', 'Guides', ArticleStatus::Published, ['workspace'], -14],
            ['Pour-over water temperature, measured', 'Guides', ArticleStatus::Published, ['coffee'], -9],
            ['Three-season tents: what the rating actually means', 'Comparisons', ArticleStatus::Published, ['outdoor', 'buying-guide'], -5],
            ['Re-waxing a cotton pack', 'Workshop', ArticleStatus::Published, ['outdoor', 'maintenance'], -2],
            ['Autumn range: what is landing in October', 'Product news', ArticleStatus::Draft, ['buying-guide'], null],
            ['Monitor calibration without a colorimeter', 'Guides', ArticleStatus::Scheduled, ['workspace'], null],
            ['An older comparison, kept for reference', 'Comparisons', ArticleStatus::Archived, ['audio'], -180],
        ];

        foreach ($articles as [$title, $category, $status, $articleTags, $publishedDaysAgo]) {
            $article = Article::create([
                'author_id' => $author->id,
                'article_category_id' => $categories[$category]->id,
                'title' => $title,
                'slug' => Str::slug($title),
                'summary' => Str::limit($title, 120).' — a short summary for the index page.',
                'content' => "<p>{$title}.</p><p>This is demo copy. It exists so the article index and detail pages have something with real length and shape to render, rather than a single line of lorem ipsum.</p><h2>What matters</h2><p>Three or four paragraphs of body text, a heading, and a list are enough to prove the typography holds up.</p><ul><li>Readable measure</li><li>Sensible heading scale</li><li>Room to breathe</li></ul>",
                'status' => $status,
                'featured' => $publishedDaysAgo !== null && $publishedDaysAgo > -10,
                'published_at' => $publishedDaysAgo !== null ? Carbon::now()->addDays($publishedDaysAgo) : null,
                'seo_title' => $title,
                'seo_description' => Str::limit($title, 150),
            ]);

            $article->tags()->attach(collect($articleTags)->map(fn (string $t): int => $tags[$t]->id)->all());
        }
    }

    /**
     * A handful of approved reviews so the catalogue's rating display has
     * something honest to average. Deliberately uneven: some products carry
     * none, so the "no rating yet" branch is visible too.
     */
    private function reviews(): void
    {
        $customers = User::query()
            ->whereIn('email', ['customer@example.com', 'maria.petrova@example.com', 'georgi.ivanov@example.com'])
            ->get();

        if ($customers->isEmpty()) {
            return;
        }

        $bodies = [
            5 => 'Exactly what I hoped for. Arrived quickly and the build quality is obvious the moment you unbox it.',
            4 => 'Very good overall. Docked a star only because the packaging was minimal for the price.',
            3 => 'Does the job. Nothing wrong with it, but nothing that surprised me either.',
            2 => 'Worked for a few weeks then developed a fault. Support replied quickly, still waiting on the replacement.',
        ];

        // Ratings per product SKU prefix, so the demo has a spread rather than
        // every product sitting at a flattering 4.5.
        $plan = [
            'AUR-ONE' => [5, 5, 4],
            'MER-VERSO' => [5, 4],
            'VEC-68' => [4, 5, 4, 3],
            'KES-R35' => [5, 5, 5],
            'PAL-SAUTE' => [4, 3],
            'VEC-GLIDE' => [3, 2],
            'HAL-ARC' => [5],
        ];

        foreach ($plan as $skuPrefix => $ratings) {
            $product = Product::query()->where('sku', $skuPrefix)->first();

            if ($product === null) {
                continue;
            }

            foreach ($ratings as $index => $rating) {
                $customer = $customers[$index % $customers->count()];

                // UNIQUE(user_id, product_id): one review per customer per
                // product, so extra ratings beyond the customer count are
                // recorded as guests whose account has since been erased.
                $isFirstForCustomer = $index < $customers->count();

                $product->productReviews()->create([
                    'user_id' => $isFirstForCustomer ? $customer->id : null,
                    'author_name' => $isFirstForCustomer
                        ? $customer->first_name.' '.$customer->last_name
                        : 'Verified buyer',
                    'rating' => $rating,
                    'body' => $bodies[$rating] ?? $bodies[3],
                    'approved' => true,
                ]);
            }
        }
    }

    private function coupons(): void
    {
        $now = Carbon::now();

        Coupon::create([
            'code' => 'WELCOME10', 'name' => 'Welcome 10%',
            'description' => 'Ten percent off a first order.',
            'type' => CouponType::Percentage, 'scope' => CouponScope::EntireOrder,
            'value' => '10.00', 'max_discount_amount' => '50.00', 'minimum_order_value' => '40.00',
            'starts_at' => $now->copy()->subDays(30), 'ends_at' => $now->copy()->addDays(60),
            'total_usage_limit' => 500, 'usage_limit_per_customer' => 1, 'is_active' => true,
        ]);

        Coupon::create([
            'code' => 'AUDIO25', 'name' => '€25 off audio',
            'description' => 'Twenty-five euro off anything in Audio.',
            'type' => CouponType::Fixed, 'scope' => CouponScope::Categories,
            'value' => '25.00', 'minimum_order_value' => '100.00',
            'starts_at' => $now->copy()->subDays(7), 'ends_at' => $now->copy()->addDays(14),
            'total_usage_limit' => 200, 'usage_limit_per_customer' => 2, 'is_active' => true,
        ]);

        // Expired, so the redemption path has something that must be refused.
        Coupon::create([
            'code' => 'SUMMER20', 'name' => 'Summer 20% (expired)',
            'description' => 'Kept to exercise the expired-coupon refusal.',
            'type' => CouponType::Percentage, 'scope' => CouponScope::EntireOrder,
            'value' => '20.00',
            'starts_at' => $now->copy()->subDays(120), 'ends_at' => $now->copy()->subDays(30),
            'total_usage_limit' => 1000, 'usage_limit_per_customer' => 1, 'is_active' => true,
        ]);

        Coupon::create([
            'code' => 'DORMANT', 'name' => 'Inactive campaign',
            'description' => 'Switched off rather than expired.',
            'type' => CouponType::Fixed, 'scope' => CouponScope::EntireOrder,
            'value' => '15.00', 'is_active' => false,
        ]);
    }

    private function summarise(): void
    {
        $this->command?->newLine();
        $this->command?->table(
            ['Table', 'Rows'],
            collect([
                'brands' => Brand::count(),
                'product_categories' => ProductCategory::count(),
                'attributes' => Attribute::count(),
                'attribute_values' => AttributeValue::count(),
                'products' => Product::count(),
                'product_variations' => DB::table('product_variations')->count(),
                'inventories' => DB::table('inventories')->count(),
                'inventory_movements' => DB::table('inventory_movements')->count(),
                'product_images' => ProductImage::count(),
                'product_specifications' => ProductSpecification::count(),
                'article_categories' => ArticleCategory::count(),
                'articles' => Article::count(),
                'tags' => Tag::count(),
                'coupons' => Coupon::count(),
            ])->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
        );
    }
}
