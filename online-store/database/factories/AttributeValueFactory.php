<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttributeValue>
 */
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
            // varchar(120), unique per attribute. Bounded by word count so it
            // cannot overrun — see TagFactory.
            'slug' => fake()->unique()->slug(3),
            'color_hex' => fake()->randomLetter(),
            'sort_order' => fake()->numberBetween(-10000, 10000),
        ];
    }
}
