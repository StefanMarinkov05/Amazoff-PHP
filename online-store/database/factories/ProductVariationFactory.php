<?php

declare(strict_types=1);

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
            'image_id' => ProductImage::factory(),
            'sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            'price' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_price' => fake()->randomFloat(2, 0, 99999999.99),
            'weight' => fake()->randomFloat(2, 0, 999999.99),
            'is_available' => fake()->boolean(),
        ];
    }
}
