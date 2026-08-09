<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponRedemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'email_hash' => fake()->randomLetter(),
            'discount_amount' => fake()->randomFloat(2, 0, 99999999.99),
        ];
    }
}
