<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
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
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_variation_id' => ProductVariation::factory(),
            'product_name' => fake()->regexify('[A-Za-z0-9]{100}'),
            'product_sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            'variation_name' => fake()->regexify('[A-Za-z0-9]{150}'),
            'quantity' => fake()->numberBetween(-10000, 10000),
            'unit_price' => fake()->randomFloat(2, 0, 99999999.99),
            'line_total' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'vat_rate' => fake()->randomFloat(2, 0, 999.99),
            'vat_amount' => fake()->randomFloat(2, 0, 99999999.99),
        ];
    }
}
