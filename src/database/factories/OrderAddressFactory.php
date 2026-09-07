<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AddressType;
use App\Enums\DeliveryType;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAddress>
 */
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
            'type' => fake()->randomElement(AddressType::cases()),
            'delivery_type' => fake()->randomElement(DeliveryType::cases()),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->phoneNumber(),
            'country' => fake()->countryCode(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'street' => fake()->streetName(),
            'courier_office_code' => fake()->regexify('[A-Za-z0-9]{50}'),
            'courier_office_name' => fake()->regexify('[A-Za-z0-9]{150}'),
        ];
    }
}
