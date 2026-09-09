<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderReturn>
 */
class OrderReturnFactory extends Factory
{
    protected $model = OrderReturn::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            // Default to the entry state and unresolved — the same reasoning
            // OrderFactory's `anonymized_at` gotcha teaches in reverse: a
            // test asserting the review flow needs the row to start where the
            // customer left it, not pre-approved.
            'status' => ReturnStatus::Requested,
            'reason' => fake()->sentence(),
            'resolution_note' => null,
            'requested_at' => now(),
            'resolved_at' => null,
            'refunded_amount' => null,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'status' => ReturnStatus::Approved,
            'resolution_note' => fake()->sentence(),
            'resolved_at' => now(),
        ]);
    }

    public function denied(): self
    {
        return $this->state(fn (): array => [
            'status' => ReturnStatus::Denied,
            'resolution_note' => fake()->sentence(),
            'resolved_at' => now(),
        ]);
    }

    public function refunded(): self
    {
        return $this->state(fn (): array => [
            'status' => ReturnStatus::Refunded,
            'resolution_note' => fake()->sentence(),
            'resolved_at' => now(),
            'refunded_amount' => fake()->randomFloat(2, 5, 500),
        ]);
    }
}
