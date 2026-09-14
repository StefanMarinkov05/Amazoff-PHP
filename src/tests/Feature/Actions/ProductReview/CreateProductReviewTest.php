<?php

declare(strict_types=1);

use App\Actions\ProductReview\CreateProductReview;
use App\Enums\OrderStatus;
use App\Exceptions\ReviewNotAllowedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;

/*
 * §24 only requires "bought it" - it does not say the order must still be
 * open or successfully delivered. OrderItem::reviewableBy() accepts a line
 * whose order history shows Delivered, Returned (after delivery), or
 * Cancelled from anywhere past AwaitingPayment - the customer actually
 * committed to the purchase in all three. A cancellation still sitting in
 * AwaitingPayment (the expiry sweep, a failed webhook, the customer's own
 * Cancel button) is excluded: nobody received anything there.
 *
 * Checking order_status_histories rather than orders.status is what lets a
 * later return or a staff-side Returned/Refunded move (ADR-0020: independent
 * of orders.status) leave eligibility untouched.
 *
 * The duplicate guard is a caught UNIQUE violation rather than a read-then-
 * insert, per CLAUDE.md's idempotency rule, so the assertion below is that
 * the domain exception surfaces - not a QueryException.
 */

/**
 * An order for $variation belonging to $buyer, currently at $status, having
 * passed through every status in $history along the way in order - each
 * row's previous_status is the prior entry's new_status (or New, for the
 * first), matching how TransitionOrderStatus actually writes history.
 *
 * @param  list<OrderStatus>  $history
 */
function purchaseOf(ProductVariation $variation, User $buyer, OrderStatus $status, array $history = []): Order
{
    $order = Order::factory()->create([
        'user_id' => $buyer->getKey(),
        'status' => $status,
    ]);

    $previous = OrderStatus::New;

    foreach ($history as $newStatus) {
        OrderStatusHistory::factory()->create([
            'order_id' => $order->getKey(),
            'previous_status' => $previous,
            'new_status' => $newStatus,
        ]);

        $previous = $newStatus;
    }

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
    ]);

    return $order->fresh();
}

/** Same as purchaseOf(), but the order's own history records reaching Delivered. */
function deliveredPurchaseOf(ProductVariation $variation, User $buyer, OrderStatus $status = OrderStatus::Delivered): Order
{
    return purchaseOf($variation, $buyer, $status, [OrderStatus::Delivered]);
}

it('creates an unapproved review linked to the order line', function (): void {
    $variation = cartVariation();
    $buyer = User::factory()->create();
    $order = deliveredPurchaseOf($variation, $buyer);

    $review = app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 5, 'Held up on site all winter.');

    // Never approved on creation - §24 makes moderation the gate, and
    // ApproveProductReview is the only thing that flips it.
    expect($review->approved)->toBeFalse()
        ->and($review->rating)->toBe(5)
        ->and($review->user_id)->toBe($buyer->getKey())
        ->and($review->product_id)->toBe($variation->product_id)
        // order_item_id is what lets the storefront say "verified purchase"
        // and name the variation bought without a second query.
        ->and($review->order_item_id)->toBe($order->orderItems()->sole()->getKey());
});

it('defaults the author name to the reviewer', function (): void {
    $variation = cartVariation();
    $buyer = User::factory()->create(['first_name' => 'Ivan', 'last_name' => 'Petrov']);
    deliveredPurchaseOf($variation, $buyer);

    $review = app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 4, 'Good value.');

    expect($review->author_name)->toBe('Ivan Petrov');
});

it('refuses a second review of the same product by the same user', function (): void {
    $variation = cartVariation();
    $buyer = User::factory()->create();
    deliveredPurchaseOf($variation, $buyer);

    app(CreateProductReview::class)->handle($variation->product, $buyer, 5, 'First.');

    // The domain exception, not the QueryException underneath it: the guard
    // is a caught UNIQUE violation, and a raw 1062 reaching a caller would
    // be a 500 rather than a message.
    expect(fn () => app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 3, 'Second.'))
        ->toThrow(ReviewNotAllowedException::class);

    expect(ProductReview::where('user_id', $buyer->getKey())->count())->toBe(1);
});

it('refuses a reviewer who never bought the product', function (): void {
    $variation = cartVariation();
    $stranger = User::factory()->create();

    expect(fn () => app(CreateProductReview::class)
        ->handle($variation->product, $stranger, 5, 'Never touched it.'))
        ->toThrow(ReviewNotAllowedException::class);

    expect(ProductReview::count())->toBe(0);
});

it('refuses a buyer whose order is paid but not yet delivered', function (): void {
    // The distinction §24 actually draws. Paid is not enough - the reviewer
    // has not received the thing yet.
    $variation = cartVariation();
    $buyer = User::factory()->create();
    purchaseOf($variation, $buyer, OrderStatus::Paid);

    expect(fn () => app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 5, 'Arrived quickly.'))
        ->toThrow(ReviewNotAllowedException::class);
});

it('refuses a buyer whose order was cancelled while still awaiting payment', function (): void {
    // The expiry sweep, a failed webhook, and the customer's own Cancel
    // button all produce exactly this shape with no actor - nobody received
    // anything, regardless of who or what did the cancelling.
    $variation = cartVariation();
    $buyer = User::factory()->create();
    purchaseOf($variation, $buyer, OrderStatus::Cancelled, [OrderStatus::AwaitingPayment, OrderStatus::Cancelled]);

    expect(fn () => app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 1, 'Never arrived.'))
        ->toThrow(ReviewNotAllowedException::class);
});

it('accepts a buyer whose order was cancelled after they committed to it', function (): void {
    // Cancelled from Confirmed (a paid or COD order staff later cancel) -
    // the customer had already committed, unlike the AwaitingPayment case.
    $variation = cartVariation();
    $buyer = User::factory()->create();
    purchaseOf($variation, $buyer, OrderStatus::Cancelled, [OrderStatus::Confirmed, OrderStatus::Cancelled]);

    $review = app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 1, 'Cancelled after I paid, never got it.');

    expect($review->exists)->toBeTrue();
});

it('refuses a stranger whose own order was cancelled after commitment', function (): void {
    // Pins the whereHas grouping in OrderItem::reviewableBy(): the Cancelled
    // clause is scoped inside the same subquery as user_id, so a stranger's
    // eligible-shaped order must never unlock eligibility for someone else.
    $variation = cartVariation();
    $owner = User::factory()->create();
    purchaseOf($variation, $owner, OrderStatus::Cancelled, [OrderStatus::Confirmed, OrderStatus::Cancelled]);

    $stranger = User::factory()->create();

    expect(fn () => app(CreateProductReview::class)
        ->handle($variation->product, $stranger, 5, 'Not my order.'))
        ->toThrow(ReviewNotAllowedException::class);
});

it('accepts a buyer whose order was later moved to Returned by staff', function (): void {
    // ADR-0020: a whole-order Returned/Refunded move is independent of the
    // customer-facing return aggregate and does not retract that the order
    // was, at one point, actually delivered.
    $variation = cartVariation();
    $buyer = User::factory()->create();
    deliveredPurchaseOf($variation, $buyer, OrderStatus::Returned);

    $review = app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 2, 'Sent it back, still reviewing it.');

    expect($review->exists)->toBeTrue();
});

it('accepts a buyer whose order was returned without ever reaching Delivered', function (): void {
    // Shipped -> Returned is a legal transition on its own (a refused-at-
    // the-door / return-to-sender case) - reviewableBy() must not require a
    // Delivered row when a Returned one exists.
    $variation = cartVariation();
    $buyer = User::factory()->create();
    purchaseOf($variation, $buyer, OrderStatus::Returned, [
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::ReadyForShipment,
        OrderStatus::Shipped,
        OrderStatus::Returned,
    ]);

    $review = app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 2, 'Refused delivery, still have opinions.');

    expect($review->exists)->toBeTrue();
});

it('accepts a delivered order with a refunded customer return', function (): void {
    // A customer RequestReturn/RefundReturn never touches orders.status
    // (returns.md), so the order here stays Delivered - this is the ordinary
    // case, kept as its own test since it is the one §24 is really about.
    $variation = cartVariation();
    $buyer = User::factory()->create();
    deliveredPurchaseOf($variation, $buyer);

    $review = app(CreateProductReview::class)
        ->handle($variation->product, $buyer, 3, 'Returned one item, kept the rest.');

    expect($review->exists)->toBeTrue();
});

it('accepts a purchase of any variation of the product', function (): void {
    // An order line records the variation bought; the review is about the
    // product, so any of its variations counts as having bought it.
    $bought = cartVariation();
    $sibling = ProductVariation::factory()->create([
        'product_id' => $bought->product_id,
        'is_default' => false,
    ]);

    $buyer = User::factory()->create();
    deliveredPurchaseOf($bought, $buyer);

    $review = app(CreateProductReview::class)
        ->handle($sibling->product, $buyer, 4, 'Bought the black one.');

    expect($review->exists)->toBeTrue();
});

it('refuses a rating outside one to five before writing anything', function (): void {
    $variation = cartVariation();
    $buyer = User::factory()->create();
    deliveredPurchaseOf($variation, $buyer);

    foreach ([0, 6, -1] as $rating) {
        expect(fn () => app(CreateProductReview::class)
            ->handle($variation->product, $buyer, $rating, 'Out of range.'))
            ->toThrow(ReviewNotAllowedException::class);
    }

    expect(ProductReview::count())->toBe(0);
});
