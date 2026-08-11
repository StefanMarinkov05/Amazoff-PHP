<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'order_item_id' => OrderItem::factory(),
            'author_name' => fake()->regexify('[A-Za-z0-9]{100}'),
            // tinyInteger accepts -128..127; the scale is five stars. Weighted
            // high because real review distributions are.
            'rating' => fake()->randomElement([5, 5, 5, 4, 4, 4, 3, 2, 1]),
            'body' => fake()->text(),
            'approved' => fake()->boolean(),
        ];
    }
}
