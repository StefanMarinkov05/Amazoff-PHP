<?php

namespace Database\Factories;

use App\Models\;
use App\Models\Address;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'carrier_id' => ::factory(),
            'tracking_number' => fake()->word(),
            'status' => fake()->randomElement(["pending","shipped","in_transit","delivered","returned"]),
            'label_url' => fake()->word(),
            'courier_tracking_url' => fake()->word(),
            'shipping_address_id' => Address::factory(),
            'recipient_name' => fake()->regexify('[A-Za-z0-9]{100}'),
            'recipient_phone' => fake()->regexify('[A-Za-z0-9]{30}'),
            'street' => fake()->streetName(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country' => fake()->country(),
            'shipped_at' => fake()->dateTime(),
            'delivered_at' => fake()->dateTime(),
            'created_at' => fake()->dateTime(),
            'updated_at' => fake()->dateTime(),
        ];
    }
}
