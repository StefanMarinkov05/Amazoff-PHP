<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use App\Models\User;
use RuntimeException;

/**
 * A review was refused before it was written.
 *
 * §24 restricts reviews to customers who actually bought the product, which
 * is the rule `CreateProductReview` exists to enforce — a review from someone
 * who never purchased is the spam vector the section is guarding against.
 *
 * `alreadyReviewed()` is the caught-violation half of
 * `UNIQUE(user_id, product_id)` rather than a check-then-act read. CLAUDE.md
 * requires idempotency that way round: two simultaneous submissions both pass
 * an existence check, and only the constraint can actually stop the second.
 */
class ReviewNotAllowedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Product $product,
        public readonly ?User $user = null,
    ) {
        parent::__construct($message);
    }

    public static function notPurchased(Product $product, User $user): self
    {
        return new self(sprintf(
            '%s has no delivered order containing %s, so cannot review it.',
            $user->email,
            $product->name,
        ), $product, $user);
    }

    public static function alreadyReviewed(Product $product, User $user): self
    {
        return new self(sprintf(
            '%s has already reviewed %s.',
            $user->email,
            $product->name,
        ), $product, $user);
    }

    public static function ratingOutOfRange(Product $product, int $rating): self
    {
        return new self(sprintf(
            'Rating %d is outside the 1-5 range.',
            $rating,
        ), $product);
    }
}
