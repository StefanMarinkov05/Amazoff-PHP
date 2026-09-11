<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->regexify('[A-Za-z0-9]{50}'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->phoneNumber(),
            'country' => fake()->countryCode(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'street' => fake()->streetName(),
            'is_default_billing' => fake()->boolean(),
            'is_default_shipping' => fake()->boolean(),
        ];
    }
}
