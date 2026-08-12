<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BrandFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // varchar(100), unique. Bounded by word count so it cannot
            // overrun — see TagFactory.
            'slug' => fake()->unique()->slug(3),
        ];
    }
}
