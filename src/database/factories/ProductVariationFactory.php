<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WeightUnit;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariation>
 */
class ProductVariationFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $price = fake()->boolean(40) ? fake()->randomFloat(2, 5, 2000) : null;

        return [
            'product_id' => Product::factory(),
            'sku' => fake()->regexify('[A-Za-z0-9]{64}'),
            // Null means "inherit the product's price", which is the common
            // case — only variants that genuinely cost more or less override.
            'price' => $price,
            'discount_price' => $price !== null && fake()->boolean(25)
                ? round($price * fake()->randomFloat(2, 0.5, 0.9), 2)
                : null,
            // Null inherits the product's, same as price above — most
            // variations do not genuinely weigh or measure differently from
            // their product. No length/width/height override here for the
            // same reason: a factory-level default would obscure the actual
            // decision, which is per-fixture (open-schema-questions.md #3).
            'weight_g' => fake()->boolean(20) ? fake()->numberBetween(10, 40000) : null,
            'weight_display_unit' => fake()->randomElement(WeightUnit::cases()),
            'is_available' => fake()->boolean(),
            'is_default' => false,
        ];
    }
}
