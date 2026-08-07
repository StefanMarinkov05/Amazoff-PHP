<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DiscountFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'type' => fake()->randomElement(["percentage","product","order"]),
            'value' => fake()->randomFloat(2, 0, 999.99),
            'code' => fake()->regexify('[A-Za-z0-9]{50}'),
            'description' => fake()->text(),
            'minimum_order_value' => fake()->randomFloat(2, 0, 99999999.99),
            'start_date' => fake()->dateTime(),
            'end_date' => fake()->dateTime(),
            'total_usage_limit' => fake()->numberBetween(-10000, 10000),
            'usage_limit_per_customer' => fake()->numberBetween(-10000, 10000),
            'is_active' => fake()->boolean(),
        ];
    }
}
