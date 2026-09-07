<?php

declare(strict_types=1);

namespace App\Actions\ProductReview;

use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Reverses approval, hiding a review from everyone but its author and a
 * moderator again — for one approved in error, or one that needs a second
 * look.
 *
 * Same ability as ApproveProductReview and deliberately so: the catalogue has
 * no separate `unapprove_product_review` permission, because unapproving is
 * not a distinct capability from approving, it is the same moderation power
 * used the other direction. §24 assigns that power to administrators.
 *
 * Authorizes `approve_product_review` via ProductReviewPolicy::approve().
 * Locks nothing.
 * ADR-0007
 */
final class UnapproveProductReview
{
    public function handle(ProductReview $review, ?User $actor = null): ProductReview
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('approve', $review);
        }

        $review->update(['approved' => false]);

        return $review->refresh();
    }
}
