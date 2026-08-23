<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttributeInputType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

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
 * `updateOrCreate` on the slug throughout: re-running must not duplicate, and
 * the slug is the natural key the fixtures already use.
 */
class CatalogueReferenceSeeder extends Seeder
{
    /**
     * Parent categories, then their children. Flat two levels — deep trees
     * are a merchandising decision nobody has made, and `parent_id` supports
     * more whenever someone does.
     *
     * @var array<string, array{name: string, children: array<string, string>}>
     */
    private const CATEGORIES = [
        'power-tools' => [
            'name' => 'Power Tools',
            'children' => [
                'drills-drivers' => 'Drills & Drivers',
                'saws' => 'Saws',
                'sanders-grinders' => 'Sanders & Grinders',
            ],
        ],
        'hand-tools' => [
            'name' => 'Hand Tools',
            'children' => [
                'wrenches' => 'Wrenches',
                'screwdrivers' => 'Screwdrivers',
                'measuring' => 'Measuring',
            ],
        ],
        'garden' => [
            'name' => 'Garden',
            'children' => [
                'mowers' => 'Mowers',
                'watering' => 'Watering',
            ],
        ],
        'workwear' => [
            'name' => 'Workwear',
            'children' => [
                'gloves' => 'Gloves',
                'footwear' => 'Footwear',
            ],
        ],
    ];

    /** @var array<string, string> */
    private const BRANDS = [
        'boschtech' => 'BoschTech',
        'makita' => 'Makita',
        'dewalt' => 'DeWalt',
        'stanley' => 'Stanley',
        'hikoki' => 'HiKOKI',
        'einhell' => 'Einhell',
        'gardena' => 'Gardena',
        'husqvarna' => 'Husqvarna',
    ];

    /**
     * Attribute slug => [input type, filterable, values as slug => [label, hex]].
     *
     * `colour` carries hex codes because `AttributeInputType::Color` renders a
     * swatch from `color_hex`; the others are plain selects and leave it null.
     *
     * @var array<string, array{type: AttributeInputType, filterable: bool, values: array<string, array{0: string, 1: string|null}>}>
     */
    private const ATTRIBUTES = [
        'colour' => [
            'type' => AttributeInputType::Color,
            'filterable' => true,
            'values' => [
                'black' => ['Black', '#111111'],
                'red' => ['Red', '#C0392B'],
                'blue' => ['Blue', '#2472A4'],
                'yellow' => ['Yellow', '#F1C40F'],
                'green' => ['Green', '#27AE60'],
                'grey' => ['Grey', '#7F8C8D'],
            ],
        ],
        'size' => [
            'type' => AttributeInputType::Select,
            'filterable' => true,
            'values' => [
                's' => ['S', null],
                'm' => ['M', null],
                'l' => ['L', null],
                'xl' => ['XL', null],
                'xxl' => ['XXL', null],
            ],
        ],
        'power-source' => [
            'type' => AttributeInputType::Select,
            'filterable' => true,
            'values' => [
                'corded' => ['Corded', null],
                'battery' => ['Battery', null],
                'petrol' => ['Petrol', null],
                'manual' => ['Manual', null],
            ],
        ],
        'capacity' => [
            'type' => AttributeInputType::Select,
            'filterable' => false,
            'values' => [
                '2ah' => ['2.0 Ah', null],
                '4ah' => ['4.0 Ah', null],
                '5ah' => ['5.0 Ah', null],
            ],
        ],
    ];

    public function run(): void
    {
        $this->seedCategories();
        $this->seedBrands();
        $this->seedAttributes();
    }

    private function seedCategories(): void
    {
        foreach (self::CATEGORIES as $slug => $category) {
            $parent = ProductCategory::updateOrCreate(
                ['slug' => $slug],
                ['name' => $category['name'], 'parent_id' => null],
            );

            foreach ($category['children'] as $childSlug => $childName) {
                ProductCategory::updateOrCreate(
                    ['slug' => $childSlug],
                    ['name' => $childName, 'parent_id' => $parent->getKey()],
                );
            }
        }
    }

    private function seedBrands(): void
    {
        foreach (self::BRANDS as $slug => $name) {
            Brand::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }

    private function seedAttributes(): void
    {
        $sortOrder = 0;

        foreach (self::ATTRIBUTES as $slug => $attribute) {
            $model = Attribute::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => ucfirst(str_replace('-', ' ', $slug)),
                    'input_type' => $attribute['type'],
                    'is_filterable' => $attribute['filterable'],
                    'sort_order' => $sortOrder++,
                ],
            );

            $valueOrder = 0;

            foreach ($attribute['values'] as $valueSlug => [$label, $hex]) {
                AttributeValue::updateOrCreate(
                    ['slug' => $valueSlug, 'attribute_id' => $model->getKey()],
                    ['value' => $label, 'color_hex' => $hex, 'sort_order' => $valueOrder++],
                );
            }
        }
    }
}
