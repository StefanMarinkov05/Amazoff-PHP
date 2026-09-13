<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NewsletterSubscriber>
 */
class NewsletterSubscriberFactory extends Factory
{
    /**
     * A confirmed subscriber by default — the common case, and the one a
     * test that just wants "someone on the list" means. Use ->pending() or
     * ->unsubscribed() for the others.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'status' => NewsletterStatus::Subscribed,
            'confirmation_token' => Str::random(64),
            'subscribed_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
            'confirmed_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => NewsletterStatus::Pending,
            'confirmed_at' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (): array => [
            'status' => NewsletterStatus::Unsubscribed,
        ]);
    }
}
