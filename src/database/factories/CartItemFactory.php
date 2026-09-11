<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_variation_id' => ProductVariation::factory(),
            // §10: cart quantity never falls below one.
            'quantity' => fake()->numberBetween(1, 5),
        ];
    }
}
