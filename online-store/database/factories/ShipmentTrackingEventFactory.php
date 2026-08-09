<?php

namespace Database\Factories;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentTrackingEventFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'status' => fake()->randomElement(["pending","shipped","in_transit","delivered","returned","cancelled"]),
            'event_time' => fake()->dateTime(),
            'description' => fake()->text(),
        ];
    }
}
