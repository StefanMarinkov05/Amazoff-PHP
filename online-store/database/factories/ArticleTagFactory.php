<?php

namespace Database\Factories;

use App\Models\;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArticleTagFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'tag_id' => ::factory(),
        ];
    }
}
