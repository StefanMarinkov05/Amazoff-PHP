<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'street' => fake()->streetName(),
            'postcode' => fake()->postcode(),
            'phone_number' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'country' => fake()->country(),
        ];
    }
}
