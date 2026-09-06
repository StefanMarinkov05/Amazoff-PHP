<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
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
