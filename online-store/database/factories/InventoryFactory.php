<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variation_id' => ProductVariation::factory(),
            'current_quantity' => fake()->numberBetween(-10000, 10000),
            'reserved_quantity' => fake()->numberBetween(-10000, 10000),
            'available_quantity' => fake()->numberBetween(-10000, 10000),
            'sold_quantity' => fake()->numberBetween(-10000, 10000),
            'returned_quantity' => fake()->numberBetween(-10000, 10000),
            'damaged_quantity' => fake()->numberBetween(-10000, 10000),
            'product_variation_id' => ProductVariation::factory(),
        ];
    }
}
