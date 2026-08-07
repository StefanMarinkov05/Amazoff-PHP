<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'serial_number' => fake()->word(),
            'status' => fake()->randomElement(["pending","preparing","delivering","delivered","completed","cancelled","refunded"]),
            'payment_status' => fake()->randomElement(["pending","processing","paid","failed","cancelled","refunded","partially_refunded"]),
            'total_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'shipping_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'billing_address_id' => Address::factory(),
            'delivery_address_id' => Address::factory(),
        ];
    }
}
