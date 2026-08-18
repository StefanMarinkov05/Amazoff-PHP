<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductReview;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * No create in the panel: §24 allows a review only from someone who bought
 * the product, which is a storefront path with its own eligibility rules, so
 * create_product_review is not in the catalogue.
 */
class ProductReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_product_review');
    }

    public function view(User $user, ProductReview $productReview): bool
    {
        // An unapproved review is visible to its author and to moderators;
        // §24 makes approval the gate for everyone else.
        return $user->can('view_product_review')
            || $productReview->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProductReview $productReview): bool
    {
        return $user->can('update_product_review');
    }

    /** §24: administrators can remove inappropriate reviews. */
    public function delete(User $user, ProductReview $productReview): bool
    {
        return $user->can('delete_product_review');
    }

    /**
     * §24 makes approval a moderation step rather than an edit, so it is a
     * distinct ability — content_editor holds it without holding update.
     */
    public function approve(User $user, ProductReview $productReview): bool
    {
        return $user->can('approve_product_review');
    }
}
