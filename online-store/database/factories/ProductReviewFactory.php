<?php

namespace Database\Factories;

use App\Models\;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => ::factory(),
            'description' => fake()->text(),
            'rating' => fake()->randomFloat(2, 0, 9.99),
            'approved' => fake()->boolean(),
            'created_at' => fake()->dateTime(),
        ];
    }
}
