<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'serial_number' => fake()->regexify('[A-Za-z0-9]{50}'),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'status' => fake()->randomElement(OrderStatus::cases()),
            'payment_status' => fake()->randomElement(PaymentStatus::cases()),
            'payment_method' => fake()->randomElement(PaymentMethod::cases()),
            'currency' => fake()->randomLetter(),
            'subtotal_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'discount_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'shipping_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'vat_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'total_amount' => fake()->randomFloat(2, 0, 99999999.99),
            'customer_note' => fake()->text(),
            'internal_note' => fake()->text(),
            'invoice_required' => fake()->boolean(),
            'invoice_company' => fake()->regexify('[A-Za-z0-9]{150}'),
            'invoice_vat_number' => fake()->regexify('[A-Za-z0-9]{30}'),
            'invoice_eik' => fake()->regexify('[A-Za-z0-9]{20}'),
            'anonymized_at' => fake()->dateTime(),
        ];
    }
}
