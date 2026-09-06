<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'carrier_id' => Carrier::factory(),
            'shipment_number' => fake()->regexify('[A-Za-z0-9]{100}'),
            'tracking_number' => fake()->regexify('[A-Za-z0-9]{100}'),
            'status' => fake()->randomElement(ShipmentStatus::cases()),
            'raw_status' => fake()->regexify('[A-Za-z0-9]{100}'),
            'label_path' => fake()->regexify('[A-Za-z0-9]{255}'),
            'courier_tracking_url' => fake()->regexify('[A-Za-z0-9]{255}'),
            'cod_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'weight' => fake()->randomFloat(2, 0, 999999.99),
            'shipped_at' => fake()->dateTime(),
            'delivered_at' => fake()->dateTime(),
        ];
    }
}
