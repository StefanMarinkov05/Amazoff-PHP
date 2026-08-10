<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'product_category_id' => ProductCategory::factory(),
            'brand_id' => Brand::factory(),
            'name' => fake()->name(),
            'slug' => fake()->slug(),
            'sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            'short_description' => fake()->regexify('[A-Za-z0-9]{255}'),
            'description' => fake()->text(),
            'regular_price' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_price' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_starts_at' => fake()->dateTime(),
            'discount_ends_at' => fake()->dateTime(),
            'vat_rate' => fake()->randomFloat(2, 0, 999.99),
            'min_order_quantity' => fake()->numberBetween(-10000, 10000),
            'weight' => fake()->randomFloat(2, 0, 999999.99),
            'dimensions' => fake()->regexify('[A-Za-z0-9]{100}'),
            'is_available' => fake()->boolean(),
            'is_featured' => fake()->boolean(),
            'seo_title' => fake()->regexify('[A-Za-z0-9]{100}'),
            'seo_description' => fake()->regexify('[A-Za-z0-9]{255}'),
        ];
    }
}
