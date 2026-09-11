<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $currentQuantity = fake()->numberBetween(0, 500);

        return [
            'product_variation_id' => ProductVariation::factory(),
            'current_quantity' => $currentQuantity,
            // §20: available = current - reserved, so a reservation above the
            // quantity on hand is the lost-update bug the database now blocks.
            'reserved_quantity' => fake()->numberBetween(0, $currentQuantity),
            'sold_quantity' => fake()->numberBetween(0, 500),
            'returned_quantity' => fake()->numberBetween(0, 20),
            'damaged_quantity' => fake()->numberBetween(0, 10),
        ];
    }
}
