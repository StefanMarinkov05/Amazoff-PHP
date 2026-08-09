<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'source_address_id' => Address::factory(),
            'type' => fake()->randomElement(["billing","delivery"]),
            'delivery_type' => fake()->randomElement(["address","office"]),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->phoneNumber(),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'street' => fake()->streetName(),
            'courier_office_code' => fake()->regexify('[A-Za-z0-9]{50}'),
            'courier_office_name' => fake()->regexify('[A-Za-z0-9]{150}'),
        ];
    }
}
