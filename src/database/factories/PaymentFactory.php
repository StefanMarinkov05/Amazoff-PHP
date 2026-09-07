<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 10, 3000);

        return [
            'order_id' => Order::factory(),
            'method' => fake()->randomElement(PaymentMethod::cases()),
            'status' => fake()->randomElement(PaymentStatus::cases()),
            // char(3): randomLetter() produced a single character that MySQL
            // then padded, so every payment read back as a one-letter currency.
            'currency' => 'EUR',
            'amount' => $amount,
            // Bounded by what was captured — refunding more than was taken is
            // rejected by the database and is not a state the API can produce.
            'refunded_amount' => fake()->boolean(20)
                ? round($amount * fake()->randomFloat(2, 0.1, 1.0), 2)
                : 0,
            'stripe_payment_intent_id' => fake()->regexify('[A-Za-z0-9]{255}'),
            'stripe_checkout_session_id' => fake()->regexify('[A-Za-z0-9]{255}'),
            'paid_at' => fake()->dateTime(),
        ];
    }
}
