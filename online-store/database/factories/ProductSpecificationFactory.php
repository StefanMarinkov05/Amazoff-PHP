<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductSpecificationFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->name(),
            'value' => fake()->regexify('[A-Za-z0-9]{100}'),
            'sort_order' => fake()->numberBetween(-10000, 10000),
        ];
    }
}
