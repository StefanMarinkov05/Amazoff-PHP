<?php

namespace Database\Factories;

use App\Models\;
use App\Models\Order;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'product_id' => ::factory(),
            'variation_id' => ProductVariation::factory(),
            'product_name' => fake()->regexify('[A-Za-z0-9]{100}'),
            'product_sku' => fake()->regexify('[A-Za-z0-9]{50}'),
            'quantity' => fake()->numberBetween(-10000, 10000),
            'unit_price' => fake()->randomFloat(2, 0, 99999999.99),
            'order_id' => Order::factory(),
            'product_variation_id' => ProductVariation::factory(),
        ];
    }
}
