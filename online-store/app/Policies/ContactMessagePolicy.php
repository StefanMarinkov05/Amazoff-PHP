<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactMessage;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * No create: a contact message arrives from the public form (§26). Delete
 * exists for GDPR erasure — see `explanation/gdpr.md`.
 *
 * §3.3 denies content_editor "sensitive customer data", which these are: a
 * contact message carries a name, an email, and whatever the sender chose to
 * write. content_editor holds no permission for this model.
 */
class ContactMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_contact_message');
    }

    public function view(User $user, ContactMessage $contactMessage): bool
    {
        return $user->can('view_contact_message');
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Marking handled or attaching an internal note, not rewriting the message. */
    public function update(User $user, ContactMessage $contactMessage): bool
    {
        return $user->can('update_contact_message');
    }

    public function delete(User $user, ContactMessage $contactMessage): bool
    {
        return $user->can('delete_contact_message');
    }
}
