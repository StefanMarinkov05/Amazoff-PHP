<?php

declare(strict_types=1);

namespace App\Actions\Contact;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;

/**
 * Completes a double opt-in — the visitor clicked the link in the
 * `NewsletterConfirmation` email (ADR-0019).
 *
 * Matched on `confirmation_token` alone: the token is 64 random characters
 * on a UNIQUE column, so possessing it is the proof. A `Pending` row moves
 * to `Subscribed` and `confirmed_at` is stamped. An already-`Subscribed`
 * row with the same token is a no-op (the link was clicked twice) — still a
 * success from the visitor's point of view. Any other state, or no match,
 * is a failure the caller reports without saying which.
 */
final class ConfirmNewsletterSubscription
{
    public function handle(string $token): ?NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()
            ->where('confirmation_token', $token)
            ->first();

        if ($subscriber === null) {
            return null;
        }

        if ($subscriber->status === NewsletterStatus::Subscribed) {
            return $subscriber;
        }

        if ($subscriber->status !== NewsletterStatus::Pending) {
            return null;
        }

        $subscriber->update([
            'status' => NewsletterStatus::Subscribed,
            'confirmed_at' => now(),
        ]);

        return $subscriber->refresh();
    }
}
