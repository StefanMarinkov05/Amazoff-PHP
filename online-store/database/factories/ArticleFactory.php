<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArticleStatus;
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
            'article_category_id' => ArticleCategory::factory(),
            'title' => fake()->sentence(4),
            'slug' => fake()->slug(),
            'summary' => fake()->text(),
            'content' => fake()->paragraphs(3, true),
            'main_image_path' => fake()->regexify('[A-Za-z0-9]{255}'),
            'status' => fake()->randomElement(ArticleStatus::cases()),
            'featured' => fake()->boolean(),
            'seo_title' => fake()->regexify('[A-Za-z0-9]{100}'),
            'seo_description' => fake()->regexify('[A-Za-z0-9]{255}'),
            'published_at' => fake()->dateTime(),
        ];
    }
}
