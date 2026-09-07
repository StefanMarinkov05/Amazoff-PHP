<?php

declare(strict_types=1);

namespace App\Actions\ProductReview;

use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Marks a review approved, making it visible to everyone rather than only its
 * author and a moderator (§24).
 *
 * A single column on a single table, which sits below ADR-0007's bar for an
 * Action. Built as one anyway because approving is a distinct ability from
 * editing: §24 makes it a moderation step, so the catalogue carries
 * `approve_product_review` separately from `update_product_review` and the
 * policy checks `approve`. Reaching for `update` here would be wrong and
 * would look right. One class means one place that decision lives, for the
 * panel today and a storefront or queued caller later.
 *
 * Takes no lock: one UPDATE, nothing read first to decide anything, so there
 * is no check-then-act window — same reasoning as SetMainProductImage.
 *
 * Authorizes `approve_product_review` via ProductReviewPolicy::approve().
 * Locks nothing.
 * ADR-0007
 */
final class ApproveProductReview
{
    public function handle(ProductReview $review, ?User $actor = null): ProductReview
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('approve', $review);
        }

        $review->update(['approved' => true]);

        return $review->refresh();
    }
}
