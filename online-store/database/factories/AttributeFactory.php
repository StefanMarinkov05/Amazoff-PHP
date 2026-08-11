<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttributeInputType;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttributeFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // varchar(60). Bounded by word count so it cannot overrun — see
            // TagFactory, and the truncation entry in how-to/troubleshooting.md.
            'slug' => fake()->unique()->slug(2),
            'input_type' => fake()->randomElement(AttributeInputType::cases()),
            'is_filterable' => fake()->boolean(),
            'sort_order' => fake()->numberBetween(-10000, 10000),
        ];
    }
}
