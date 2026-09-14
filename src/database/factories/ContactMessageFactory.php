<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'subject' => fake()->regexify('[A-Za-z0-9]{100}'),
            'message' => fake()->text(),
            'handled_at' => null,
            'internal_note' => null,
        ];
    }

    /**
     * A message staff have already dealt with. The default is unhandled,
     * which is what an inbox screen needs to be worth looking at.
     */
    public function handled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'handled_at' => fake()->dateTimeBetween('-1 month'),
            'internal_note' => fake()->sentence(),
        ]);
    }
}
