<?php

declare(strict_types=1);

namespace App\Actions\ProductReview;

use App\Enums\OrderStatus;
use App\Exceptions\ReviewNotAllowedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes a customer review, enforcing §24's rule that only someone who bought
 * the product may review it.
 *
 * The counterpart to `ApproveProductReview`, which until now could moderate
 * reviews that nothing in the application could create. Reviews arrive
 * unapproved — §24 makes moderation the gate, so `approved` is false here
 * regardless of who is asking, and only `ApproveProductReview` sets it.
 *
 * ## Verified purchase, concretely
 *
 * "Bought it" means an `order_items` row for one of this product's variations
 * on an order belonging to this user that reached `Delivered`. Delivered
 * rather than merely paid: §24 is about reviewing a product you have, and a
 * paid-but-unshipped order has not produced an opinion worth trusting yet.
 *
 * The matching `order_item_id` is stored, which is what lets the storefront
 * render "verified purchase" and which variation was bought without a second
 * query — `product_reviews.order_item_id` exists for exactly this and was
 * unused until now.
 *
 * ## Why the duplicate check is a caught violation
 *
 * `UNIQUE(user_id, product_id)` already forbids a second review. Reading
 * first and inserting after is the check-then-act race CLAUDE.md rules out:
 * two submissions from the same customer both pass the read, and only the
 * constraint stops the second. So the insert is attempted and the violation
 * translated, rather than guarded.
 *
 * A guest review is out of scope: `user_id` is nullable in the schema, but
 * §24's verified-purchase rule has nothing to check against without a user,
 * and `UNIQUE(user_id, product_id)` does not constrain nulls in MySQL — so a
 * guest could review the same product indefinitely. This Action requires a
 * user; a guest path would need its own rule and its own ADR.
 *
 * Authorizes nothing. `ProductReviewPolicy::create()` gates the *panel*,
 * where staff do not author reviews; the customer writing one is the owner of
 * the act, and ownership is proven by the purchase check above rather than by
 * a permission. The `?User $actor` stays for ADR-0007's shape and is the
 * reviewer.
 * ADR-0007 · reference/write-rules/product.md
 */
final class CreateProductReview
{
    /**
     * @param  User  $reviewer  The customer writing it, and the ownership
     *                          check's subject — not a staff actor.
     *
     * @throws ReviewNotAllowedException
     */
    public function handle(
        Product $product,
        User $reviewer,
        int $rating,
        string $body,
        ?string $authorName = null,
    ): ProductReview {
        if ($rating < 1 || $rating > 5) {
            throw ReviewNotAllowedException::ratingOutOfRange($product, $rating);
        }

        $orderItem = $this->purchasedItem($product, $reviewer);

        if ($orderItem === null) {
            throw ReviewNotAllowedException::notPurchased($product, $reviewer);
        }

        try {
            return DB::transaction(fn (): ProductReview => ProductReview::create([
                'user_id' => $reviewer->getKey(),
                'product_id' => $product->getKey(),
                'order_item_id' => $orderItem->getKey(),
                'author_name' => $authorName ?? trim("{$reviewer->first_name} {$reviewer->last_name}"),
                'rating' => $rating,
                'body' => $body,
                // Never true here. §24 makes approval a moderation decision,
                // and ApproveProductReview is where it is made.
                'approved' => false,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ReviewNotAllowedException::alreadyReviewed($product, $reviewer);
        }
    }

    /**
     * The reviewer's delivered order line for this product, if any.
     *
     * Joins through `product_variations` because an order line records the
     * variation bought, not the product — the review is about the product,
     * so any of its variations counts.
     */
    private function purchasedItem(Product $product, User $reviewer): ?OrderItem
    {
        return OrderItem::query()
            ->whereHas('order', function (Builder $query) use ($reviewer): Builder {
                /** @var Builder<Order> $query */
                return $query
                    ->where('user_id', $reviewer->getKey())
                    ->where('status', OrderStatus::Delivered);
            })
            ->whereHas('productVariation', function (Builder $query) use ($product): Builder {
                /** @var Builder<ProductVariation> $query */
                return $query->where('product_id', $product->getKey());
            })
            ->first();
    }
}
