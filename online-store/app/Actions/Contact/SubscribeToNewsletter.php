<?php

declare(strict_types=1);

namespace App\Actions\Contact;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Subscribes an email address, resurrecting a previously unsubscribed row.
 *
 * An Action rather than an inline `create()` because `NewsletterSubscriberForm`
 * also writes `status`, so two callers decide it and only this one knows that
 * re-subscribing reverses an unsubscribe. ADR-0007's "no second writer" test
 * is what puts it here.
 *
 * Idempotent through the UNIQUE index on `email` plus a caught violation,
 * never `exists()` then insert: two submissions of one address in the same
 * moment both pass that check and the loser crashes.
 */
final class SubscribeToNewsletter
{
    public function handle(string $email, ?User $actor): NewsletterSubscriber
    {
        $attributes = [
            'status' => NewsletterStatus::Subscribed,
            'subscribed_at' => now(),
            'user_id' => $actor?->getKey(),
        ];

        try {
            return NewsletterSubscriber::create([...$attributes, 'email' => $email]);
        } catch (UniqueConstraintViolationException) {
            /** @var NewsletterSubscriber $existing */
            $existing = NewsletterSubscriber::query()->where('email', $email)->firstOrFail();

            // A guest who subscribed before registering has a null `user_id`;
            // claiming it here is what lets an erasure request find the row.
            $existing->update(array_filter(
                $attributes,
                static fn (mixed $value): bool => $value !== null,
            ));

            return $existing->refresh();
        }
    }
}
