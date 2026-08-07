<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->regexify('[A-Za-z0-9]{100}'),
            'entity_type' => fake()->regexify('[A-Za-z0-9]{50}'),
            'entity_id' => fake()->numberBetween(-10000, 10000),
            'description' => fake()->text(),
            'created_at' => fake()->dateTime(),
        ];
    }
}
