<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
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
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'previous_status' => fake()->randomElement(OrderStatus::cases()),
            'new_status' => fake()->randomElement(OrderStatus::cases()),
            'reason' => fake()->regexify('[A-Za-z0-9]{255}'),
            'note' => fake()->text(),
        ];
    }
}
