<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPriceHistory>
 */
class ProductPriceHistoryFactory extends Factory
{
    protected $model = ProductPriceHistory::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price' => fake()->randomFloat(2, 5, 500),
            'recorded_at' => now(),
        ];
    }

    /** Recorded `$days` days ago at `$price`. */
    public function daysAgo(int $days, string $price): self
    {
        return $this->state(fn (): array => [
            'price' => $price,
            'recorded_at' => now()->subDays($days),
        ]);
    }
}
