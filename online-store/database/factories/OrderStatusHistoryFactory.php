<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_id' => Order::factory(),
            'previous_status' => fake()->randomElement(["pending","processing","delivering","delivered","completed","cancelled","refunded"]),
            'new_status' => fake()->randomElement(["pending","processing","delivering","delivered","completed","cancelled","refunded"]),
            'reason' => fake()->regexify('[A-Za-z0-9]{100}'),
            'note' => fake()->word(),
            'changed_at' => fake()->dateTime(),
        ];
    }
}
