<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $regularPrice = fake()->randomFloat(2, 5, 2000);
        $isDiscounted = fake()->boolean(30);
        $discountStartsAt = fake()->dateTimeBetween('-2 months', '+1 month');
        // Strictly after the start: the database rejects a window that ends
        // where it begins, and such a window would never activate anyway.
        $discountEndsAt = (clone $discountStartsAt)
            ->modify('+'.fake()->numberBetween(1, 90).' days');

        return [
            'product_category_id' => ProductCategory::factory(),
            'brand_id' => Brand::factory(),
            'name' => fake()->name(),
            // varchar(100), unique. Bounded by word count so it cannot
            // overrun — see TagFactory.
            'slug' => fake()->unique()->slug(4),
            'sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            'short_description' => fake()->regexify('[A-Za-z0-9]{255}'),
            'description' => fake()->text(),
            'regular_price' => $regularPrice,
            // Null 70% of the time: most products are not on offer. When it is
            // set it must be below the regular price — the database enforces
            // that, and a "discount" above list price is meaningless anyway.
            'discount_price' => $isDiscounted
                ? round($regularPrice * fake()->randomFloat(2, 0.5, 0.9), 2)
                : null,
            'discount_starts_at' => $isDiscounted ? $discountStartsAt : null,
            'discount_ends_at' => $isDiscounted ? $discountEndsAt : null,
            // The two Bulgarian rates: 20% standard, 9% reduced.
            'vat_rate' => fake()->randomElement([20.00, 9.00]),
            'min_order_quantity' => fake()->boolean(15) ? fake()->numberBetween(2, 6) : 1,
            // Grams and millimetres — the canonical columns ADR-0013's
            // sibling decision (open-schema-questions.md #3) replaced the
            // free-text `dimensions` and decimal `weight` with.
            'weight_g' => fake()->numberBetween(50, 40000),
            'length_mm' => fake()->numberBetween(20, 2000),
            'width_mm' => fake()->numberBetween(20, 2000),
            'height_mm' => fake()->numberBetween(20, 2000),
            'dimension_display_unit' => fake()->randomElement(LengthUnit::cases()),
            'weight_display_unit' => fake()->randomElement(WeightUnit::cases()),
            'is_available' => fake()->boolean(),
            'is_featured' => fake()->boolean(),
            'seo_title' => fake()->regexify('[A-Za-z0-9]{100}'),
            'seo_description' => fake()->regexify('[A-Za-z0-9]{255}'),
        ];
    }
}
