<?php

declare(strict_types=1);

namespace App\Actions\Contact;

use App\Enums\NewsletterStatus;
use App\Mail\NewsletterConfirmation;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Starts a newsletter subscription — double opt-in (ePrivacy Art. 13,
 * ADR-0019).
 *
 * A new or previously-unsubscribed address becomes `Pending` with a fresh
 * `confirmation_token`, and a `NewsletterConfirmation` email goes out. It is
 * *not* on the list until the link in that email is clicked
 * (`ConfirmNewsletterSubscription`). An address that is already `Subscribed`
 * is left alone — no duplicate confirmation email — and the `user_id` is
 * still claimed if the subscriber has since registered, so the erasure
 * routine can find a row made as a guest.
 *
 * An Action rather than an inline `create()` because `NewsletterSubscriberForm`
 * also writes `status` (ADR-0007's "no second writer"), and because the
 * consent state machine — pending / confirmed / unsubscribed — only lives
 * in one place if it lives here.
 *
 * Idempotent through the UNIQUE index on `email` plus a caught violation,
 * never `exists()` then insert.
 *
 * @return array{subscriber: NewsletterSubscriber, confirmationSent: bool}
 */
final class SubscribeToNewsletter
{
    /** @return array{subscriber: NewsletterSubscriber, confirmationSent: bool} */
    public function handle(string $email, ?User $actor): array
    {
        $email = mb_strtolower(trim($email));

        try {
            $subscriber = NewsletterSubscriber::create([
                'email' => $email,
                'status' => NewsletterStatus::Pending,
                'confirmation_token' => Str::random(64),
                'subscribed_at' => now(),
                'user_id' => $actor?->getKey(),
            ]);

            Mail::to($subscriber->email)->queue(new NewsletterConfirmation($subscriber));

            return ['subscriber' => $subscriber, 'confirmationSent' => true];
        } catch (UniqueConstraintViolationException) {
            /** @var NewsletterSubscriber $existing */
            $existing = NewsletterSubscriber::query()->where('email', $email)->firstOrFail();

            // Claim the row for a subscriber who registered after subscribing
            // as a guest — the erasure routine finds rows by user_id.
            if ($actor !== null && $existing->user_id === null) {
                $existing->update(['user_id' => $actor->getKey()]);
            }

            // Already on the list — nothing to confirm, no second email.
            if ($existing->status === NewsletterStatus::Subscribed) {
                return ['subscriber' => $existing->refresh(), 'confirmationSent' => false];
            }

            // Pending (re-submitted) or Unsubscribed (re-consenting): a new
            // token and a fresh confirmation email. A new token invalidates
            // any link from a previous attempt.
            $existing->update([
                'status' => NewsletterStatus::Pending,
                'confirmation_token' => Str::random(64),
                'confirmed_at' => null,
                'subscribed_at' => now(),
            ]);

            Mail::to($existing->email)->queue(new NewsletterConfirmation($existing->refresh()));

            return ['subscriber' => $existing->refresh(), 'confirmationSent' => true];
        }
    }
}
