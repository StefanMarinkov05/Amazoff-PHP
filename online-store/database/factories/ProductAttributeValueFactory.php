<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductAttributeValueFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attribute_id' => ProductAttribute::factory(),
            'value' => fake()->regexify('[A-Za-z0-9]{50}'),
            'product_attribute_id' => ProductAttribute::factory(),
        ];
    }
}
