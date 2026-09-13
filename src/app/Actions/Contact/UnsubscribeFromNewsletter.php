<?php

declare(strict_types=1);

namespace App\Actions\Contact;

use App\Enums\NewsletterStatus;
use App\Mail\NewsletterUnsubscribed;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Mail;

/**
 * One-click unsubscribe, from the link every newsletter send carries
 * (GDPR Art. 7(3) — withdrawing consent must be as easy as giving it;
 * ADR-0019).
 *
 * Matched on `confirmation_token`, the same value that confirmed the
 * subscription and is kept on the row for the life of the address. A
 * `Subscribed` or `Pending` row becomes `Unsubscribed` — the row stays, it
 * is the record that this address asked not to be emailed — and one
 * acknowledgement email is sent. An already-`Unsubscribed` row is a no-op
 * (the link was clicked twice), still a success, no second acknowledgement.
 */
final class UnsubscribeFromNewsletter
{
    public function handle(string $token): ?NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()
            ->where('confirmation_token', $token)
            ->first();

        if ($subscriber === null) {
            return null;
        }

        if ($subscriber->status === NewsletterStatus::Unsubscribed) {
            return $subscriber;
        }

        $subscriber->update(['status' => NewsletterStatus::Unsubscribed]);

        Mail::to($subscriber->email)->queue(new NewsletterUnsubscribed($subscriber));

        return $subscriber->refresh();
    }
}
