<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariationFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $price = fake()->boolean(40) ? fake()->randomFloat(2, 5, 2000) : null;

        return [
            'product_id' => Product::factory(),
            'sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            // Null means "inherit the product's price", which is the common
            // case — only variants that genuinely cost more or less override.
            'price' => $price,
            'discount_price' => $price !== null && fake()->boolean(25)
                ? round($price * fake()->randomFloat(2, 0.5, 0.9), 2)
                : null,
            'weight' => fake()->randomFloat(2, 0.05, 40),
            'is_available' => fake()->boolean(),
        ];
    }
}
