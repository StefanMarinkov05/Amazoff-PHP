<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariationFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => fake()->word(),
            'price' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_price' => fake()->randomFloat(2, 0, 99999999.99),
            'stock_quantity' => fake()->numberBetween(-10000, 10000),
            'is_available' => fake()->boolean(),
            'size' => fake()->word(),
            'color' => fake()->word(),
            'weight' => fake()->randomFloat(2, 0, 999999.99),
            'material' => fake()->word(),
            'package_type' => fake()->word(),
            'image_id' => ProductImage::factory(),
            'product_image_id' => ProductImage::factory(),
        ];
    }
}
