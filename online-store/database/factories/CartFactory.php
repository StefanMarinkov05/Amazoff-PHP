<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'coupon_id' => Coupon::factory(),
            'session_id' => fake()->regexify('[A-Za-z0-9]{100}'),
            'expires_at' => fake()->dateTime(),
        ];
    }
}
