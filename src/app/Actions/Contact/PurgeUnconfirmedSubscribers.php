<?php

declare(strict_types=1);

namespace App\Actions\Contact;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;

/**
 * Deletes newsletter rows that were never confirmed (ePrivacy Art. 13 /
 * data minimisation, ADR-0019).
 *
 * A `Pending` row is an address someone typed into the footer and a
 * confirmation email that was never acted on — the shop has no consent to
 * hold it, so after a grace period it goes. 30 days is generous for
 * "clicked the link late".
 *
 * Not a transaction: an unrelated set of independent deletes, order does
 * not matter, and there is no cross-row invariant.
 */
final class PurgeUnconfirmedSubscribers
{
    private const GRACE_DAYS = 30;

    public function handle(): int
    {
        $deleted = NewsletterSubscriber::query()
            ->where('status', NewsletterStatus::Pending)
            ->where('subscribed_at', '<', now()->subDays(self::GRACE_DAYS))
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
