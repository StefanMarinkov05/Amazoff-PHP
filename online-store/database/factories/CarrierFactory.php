<?php

declare(strict_types=1);

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
            'cod_fee' => fake()->randomElement(['0.00', '1.50', '2.00']),
            'is_active' => fake()->boolean(),
        ];
    }
}
