<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttributeValueFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'attribute_id' => Attribute::factory(),
            'value' => fake()->regexify('[A-Za-z0-9]{100}'),
            'slug' => fake()->slug(),
            'color_hex' => fake()->randomLetter(),
            'sort_order' => fake()->numberBetween(-10000, 10000),
        ];
    }
}
