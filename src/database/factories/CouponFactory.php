<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $type = fake()->randomElement(CouponType::cases());
        $startsAt = fake()->dateTimeBetween('-2 months', '+1 month');
        $endsAt = (clone $startsAt)->modify('+'.fake()->numberBetween(1, 120).' days');

        return [
            'code' => fake()->regexify('[A-Za-z0-9]{50}'),
            'name' => fake()->name(),
            'description' => fake()->text(),
            'type' => $type,
            'scope' => fake()->randomElement(CouponScope::cases()),
            // A percentage coupon above 100 would pay the customer to order,
            // so the value's range depends on the type.
            'value' => $type === CouponType::Percentage
                ? fake()->randomFloat(2, 5, 50)
                : fake()->randomFloat(2, 5, 200),
            'max_discount_amount' => fake()->boolean(40) ? fake()->randomFloat(2, 20, 500) : null,
            'minimum_order_value' => fake()->boolean(50) ? fake()->randomFloat(2, 20, 300) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'total_usage_limit' => fake()->boolean(60) ? fake()->numberBetween(10, 1000) : null,
            'usage_limit_per_customer' => fake()->boolean(70) ? fake()->numberBetween(1, 5) : null,
            'is_active' => fake()->boolean(),
        ];
    }
}
