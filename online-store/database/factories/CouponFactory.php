<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'code' => fake()->regexify('[A-Za-z0-9]{50}'),
            'name' => fake()->name(),
            'description' => fake()->text(),
            'type' => fake()->randomElement(["percentage","fixed"]),
            'scope' => fake()->randomElement(["entire_order","products","categories"]),
            'value' => fake()->randomFloat(2, 0, 99999999.99),
            'max_discount_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'minimum_order_value' => fake()->randomFloat(2, 0, 99999999.99),
            'starts_at' => fake()->dateTime(),
            'ends_at' => fake()->dateTime(),
            'total_usage_limit' => fake()->numberBetween(-10000, 10000),
            'usage_limit_per_customer' => fake()->numberBetween(-10000, 10000),
            'times_used' => fake()->numberBetween(-10000, 10000),
            'is_active' => fake()->boolean(),
        ];
    }
}
