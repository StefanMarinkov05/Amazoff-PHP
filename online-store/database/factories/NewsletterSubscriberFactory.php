<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NewsletterSubscriberFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => fake()->randomElement(["subscribed","unsubscribed"]),
            'email' => fake()->safeEmail(),
            'subscribed_at' => fake()->dateTime(),
        ];
    }
}
