<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // varchar(60). fake()->slug() defaults to about six words and
            // reaches 85 characters, overrunning intermittently — long enough
            // to pass locally and fail in CI. Bounded by word count rather
            // than substr() so the value stays a well-formed slug: slug(2)
            // tops out around 40 characters.
            'slug' => fake()->unique()->slug(2),
        ];
    }
}
