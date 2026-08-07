<?php

namespace Database\Factories;

use App\Models\;
use App\Models\Cart;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartItemFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => ::factory(),
            'variation_id' => ProductVariation::factory(),
            'quantity' => fake()->numberBetween(-10000, 10000),
            'product_variation_id' => ProductVariation::factory(),
        ];
    }
}
