<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NewsletterSubscriber;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * No create: a subscription comes from the signup form (§5), and adding an
 * address by hand in the panel is how a consent record stops matching what
 * the subscriber actually agreed to. Delete exists for erasure requests.
 */
class NewsletterSubscriberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_newsletter_subscriber');
    }

    public function view(User $user, NewsletterSubscriber $newsletterSubscriber): bool
    {
        return $user->can('view_newsletter_subscriber');
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Status changes — unsubscribe honoured from the panel on request. */
    public function update(User $user, NewsletterSubscriber $newsletterSubscriber): bool
    {
        return $user->can('update_newsletter_subscriber');
    }

    public function delete(User $user, NewsletterSubscriber $newsletterSubscriber): bool
    {
        return $user->can('delete_newsletter_subscriber');
    }
}
