<?php

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
            'name' => fake()->name(),
            'slug' => fake()->slug(),
            'sku' => fake()->word(),
            'short_description' => fake()->regexify('[A-Za-z0-9]{100}'),
            'description' => fake()->text(),
            'is_available' => fake()->boolean(),
            'is_featured' => fake()->boolean(),
            'available_quantity' => fake()->numberBetween(-10000, 10000),
            'reserved_quantity' => fake()->numberBetween(-10000, 10000),
            'min_order_quantity' => fake()->numberBetween(-10000, 10000),
            'regular_price' => fake()->randomFloat(2, 0, 999999.99),
            'rate' => fake()->randomFloat(2, 0, 9.99),
            'weight' => fake()->randomFloat(2, 0, 999999.99),
            'dimensions' => fake()->word(),
            'seo_title' => fake()->regexify('[A-Za-z0-9]{100}'),
            'seo_description' => fake()->regexify('[A-Za-z0-9]{255}'),
            'product_category_id' => ProductCategory::factory(),
            'brand_id' => Brand::factory(),
        ];
    }
}
