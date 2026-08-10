<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `parent_id` is null rather than `ProductCategory::factory()`, which is
     * what Blueprint generates for a self-referencing key. That version
     * recurses forever — every category creates a parent, which creates a
     * parent. Use the `childOf()` state to build a nested category.
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Nest this category under another.
     */
    public function childOf(ProductCategory $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->id,
        ]);
    }
}
