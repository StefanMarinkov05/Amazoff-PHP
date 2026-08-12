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
        $subtotal = fake()->randomFloat(2, 10, 5000);
        $discount = fake()->boolean(25) ? round($subtotal * fake()->randomFloat(2, 0.05, 0.3), 2) : 0.0;
        $shipping = fake()->randomElement([0.0, 4.99, 6.99, 9.99]);
        $vatRate = 20.00;
        $vat = round(($subtotal - $discount) * $vatRate / (100 + $vatRate), 2);

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
            // char(3): randomLetter() wrote one character and MySQL padded it.
            'currency' => 'EUR',
            'subtotal_amount' => $subtotal,
            // Never above the subtotal — §21 discounts apply to order value,
            // and a discount exceeding the goods is a miscalculated coupon.
            'discount_amount' => $discount,
            'shipping_amount' => $shipping,
            // Prices are stored gross, so VAT is the portion already inside
            // the discounted subtotal rather than an amount added on top.
            'vat_amount' => $vat,
            'total_amount' => round($subtotal - $discount + $shipping, 2),
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
