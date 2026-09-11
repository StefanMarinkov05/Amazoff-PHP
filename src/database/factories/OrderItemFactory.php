<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 5, 2000);
        $lineTotal = round($unitPrice * $quantity, 2);
        $discountAmount = fake()->boolean(20) ? round($lineTotal * 0.1, 2) : 0.0;
        $vatRate = fake()->boolean() ? 20.00 : 9.00;

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_variation_id' => ProductVariation::factory(),
            'product_name' => fake()->regexify('[A-Za-z0-9]{100}'),
            'product_sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            'variation_name' => fake()->regexify('[A-Za-z0-9]{150}'),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            // Derived rather than drawn independently, so the snapshot §17
            // requires is internally consistent instead of three unrelated
            // numbers that happen to sit in the same row.
            'line_total' => $lineTotal,
            'discount_amount' => $discountAmount,
            'vat_rate' => $vatRate,
            'vat_amount' => round(($lineTotal - $discountAmount) * $vatRate / (100 + $vatRate), 2),
        ];
    }
}
