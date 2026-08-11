<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShipmentStatus;
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
            'status' => fake()->randomElement(ShipmentStatus::cases()),
            'raw_status' => fake()->regexify('[A-Za-z0-9]{100}'),
            'description' => fake()->text(),
            'event_time' => fake()->dateTime(),
        ];
    }
}
