<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'created_by_id' => User::factory(),
            'movement_type' => fake()->randomElement(InventoryMovementType::cases()),
            'quantity' => fake()->numberBetween(-10000, 10000),
            'note' => fake()->regexify('[A-Za-z0-9]{255}'),
        ];
    }
}
