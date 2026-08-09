<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'method' => fake()->randomElement(["card","paypal","cash_on_delivery"]),
            'currency' => fake()->regexify('[A-Za-z0-9]{10}'),
            'status' => fake()->randomElement(["pending","completed","failed","refunded"]),
            'amount' => fake()->randomFloat(2, 0, 99999999.99),
            'paid_at' => fake()->dateTime(),
        ];
    }
}
