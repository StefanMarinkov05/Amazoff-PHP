<?php

declare(strict_types=1);

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
            'method' => fake()->randomElement(['stripe', 'cash_on_delivery']),
            'status' => fake()->randomElement(['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded']),
            'currency' => fake()->randomLetter(),
            'amount' => fake()->randomFloat(2, 0, 99999999.99),
            'refunded_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'stripe_payment_intent_id' => fake()->regexify('[A-Za-z0-9]{255}'),
            'stripe_checkout_session_id' => fake()->regexify('[A-Za-z0-9]{255}'),
            'paid_at' => fake()->dateTime(),
        ];
    }
}
