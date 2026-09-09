<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\Catalogue\ProductDetails;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;
use Livewire\Livewire;

/*
 * CreateProductReview existed and was tested, but nothing on the storefront
 * called it — this is that missing wiring, on ProductDetails since that is
 * where the reviews it writes are already read and rendered.
 *
 * canReview() is a read-only mirror of the Action's own purchased/
 * already-reviewed checks, so the form can hide itself instead of only
 * failing on submit; these tests cover both that gate and the real
 * submission path through the Action.
 */

function reviewableProduct(): Product
{
    $product = Product::factory()->create(['is_available' => true]);
    $variation = ProductVariation::factory()->for($product)->create([
        'is_available' => true,
        'is_default' => true,
    ]);
    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => 10,
        'reserved_quantity' => 0,
    ]);

    return $product;
}

/** A Delivered order line for $product belonging to $buyer. */
function deliveredPurchase(Product $product, User $buyer): OrderItem
{
    $order = Order::factory()->create([
        'user_id' => $buyer->getKey(),
        'status' => OrderStatus::Delivered,
    ]);

    return OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $product->getKey(),
        'product_variation_id' => $product->productVariations()->first()->getKey(),
    ]);
}

it('does not show the review form to a guest', function (): void {
    $product = reviewableProduct();

    Livewire::test(ProductDetails::class, ['product' => $product])
        ->assertSet('canReview', false);
});

it('does not show the review form to a signed-in customer who never bought it', function (): void {
    $product = reviewableProduct();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->assertSet('canReview', false);
});

it('shows the review form to a customer with a delivered order for the product', function (): void {
    $product = reviewableProduct();
    $user = User::factory()->create();
    deliveredPurchase($product, $user);

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->assertSet('canReview', true);
});

it('does not show the review form to a customer who already reviewed it', function (): void {
    $product = reviewableProduct();
    $user = User::factory()->create();
    $orderItem = deliveredPurchase($product, $user);

    ProductReview::factory()->create([
        'user_id' => $user->getKey(),
        'product_id' => $product->getKey(),
        'order_item_id' => $orderItem->getKey(),
    ]);

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->assertSet('canReview', false);
});

it('submits a review through the real Action and shows the thank-you state', function (): void {
    $product = reviewableProduct();
    $user = User::factory()->create();
    $orderItem = deliveredPurchase($product, $user);

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->set('reviewRating', 4)
        ->set('reviewBody', 'Solid product, held up well after a season of use.')
        ->call('submitReview')
        ->assertHasNoErrors()
        ->assertSet('reviewSubmitted', true);

    $review = ProductReview::query()->where('product_id', $product->getKey())->sole();

    expect($review->user_id)->toBe($user->getKey())
        ->and($review->order_item_id)->toBe($orderItem->getKey())
        ->and($review->rating)->toBe(4)
        // Never approved on submission - §24 makes moderation the gate.
        ->and($review->approved)->toBeFalse();
});

it('refuses a review body under the minimum length', function (): void {
    $product = reviewableProduct();
    $user = User::factory()->create();
    deliveredPurchase($product, $user);

    Livewire::actingAs($user)
        ->test(ProductDetails::class, ['product' => $product])
        ->set('reviewRating', 5)
        ->set('reviewBody', 'too short')
        ->call('submitReview')
        ->assertHasErrors(['reviewBody']);

    expect(ProductReview::query()->where('product_id', $product->getKey())->exists())->toBeFalse();
});

it('surfaces the Action refusal on the form when eligibility changes between render and submit', function (): void {
    $product = reviewableProduct();
    $user = User::factory()->create();
    $orderItem = deliveredPurchase($product, $user);

    $component = Livewire::actingAs($user)->test(ProductDetails::class, ['product' => $product]);

    // A second submission slips in between this render and the click below -
    // the read-only canReview() gate cannot catch it, only the real Action's
    // UNIQUE-constraint check can.
    ProductReview::factory()->create([
        'user_id' => $user->getKey(),
        'product_id' => $product->getKey(),
        'order_item_id' => $orderItem->getKey(),
    ]);

    $component
        ->set('reviewRating', 5)
        ->set('reviewBody', 'Trying to review this a second time.')
        ->call('submitReview')
        ->assertHasErrors(['review']);

    expect(ProductReview::query()->where('product_id', $product->getKey())->count())->toBe(1);
});
