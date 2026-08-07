<?php

namespace Database\Factories;

use App\Models\ArticleCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'title' => fake()->sentence(4),
            'content' => fake()->paragraphs(3, true),
            'slug' => fake()->slug(),
            'status' => fake()->randomElement(["draft","published","archived","scheduled"]),
            'summary' => fake()->text(),
            'featured' => fake()->boolean(),
            'seo_title' => fake()->regexify('[A-Za-z0-9]{100}'),
            'seo_description' => fake()->regexify('[A-Za-z0-9]{255}'),
            'published_at' => fake()->dateTime(),
            'created_at' => fake()->dateTime(),
            'updated_at' => fake()->dateTime(),
            'user_id' => User::factory(),
            'article_category_id' => ArticleCategory::factory(),
        ];
    }
}
