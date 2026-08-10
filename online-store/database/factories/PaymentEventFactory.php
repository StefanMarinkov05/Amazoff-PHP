<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'stripe_event_id' => fake()->regexify('[A-Za-z0-9]{255}'),
            'event_type' => fake()->regexify('[A-Za-z0-9]{50}'),
            'status_before' => fake()->randomElement(['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded']),
            'status_after' => fake()->randomElement(['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded']),
            'payload' => '{}',
            'processed_at' => fake()->dateTime(),
            'note' => fake()->regexify('[A-Za-z0-9]{255}'),
        ];
    }
}
