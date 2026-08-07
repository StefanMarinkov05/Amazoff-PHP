<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CarrierFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'code' => fake()->regexify('[A-Za-z0-9]{20}'),
            'api_url' => fake()->regexify('[A-Za-z0-9]{255}'),
            'contact_phone' => fake()->regexify('[A-Za-z0-9]{30}'),
            'is_active' => fake()->boolean(),
        ];
    }
}
