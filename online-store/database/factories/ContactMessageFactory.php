<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ContactMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'subject' => fake()->regexify('[A-Za-z0-9]{100}'),
            'message' => fake()->text(),
            'created_at' => fake()->dateTime(),
        ];
    }
}
