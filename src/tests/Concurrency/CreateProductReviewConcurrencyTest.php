<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A customer double-submitting a review — the browser retry, not an attack.
 *
 * This race has a different mechanism from every other file in this suite:
 * there is NO lock. UNIQUE(user_id, product_id) is the discriminator, and
 * CreateProductReview attempts the insert and translates the violation rather
 * than reading first. CLAUDE.md's idempotency rule is explicit that this is
 * the required shape ("a UNIQUE constraint plus caught violation, never
 * check-then-act"), so what this test proves is that the translation actually
 * happens under real contention.
 *
 * The assertion that matters is the second one: a raw QueryException escaping
 * would be a 500 on a review form, and it is exactly what a check-then-act
 * implementation produces when the read passes in both processes.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_reviews',
        'order_items',
        'order_status_histories',
        'orders',
        'inventories',
        'product_variations',
        'products',
        'product_categories',
        'brands',
        'users',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

it('writes exactly one review when the same customer submits twice at once', function (): void {
    $variation = ProductVariation::factory()->create();
    /** @var Product $product */
    $product = $variation->product;

    $buyer = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $buyer->getKey(),
        'status' => OrderStatus::Delivered,
    ]);

    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'new_status' => OrderStatus::Delivered,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $product->getKey(),
        'product_variation_id' => $variation->getKey(),
    ]);

    $outputs = runRaceWorkers([
        ['action' => 'create-review', 'ids' => [$product->getKey(), $buyer->getKey()]],
        ['action' => 'create-review', 'ids' => [$product->getKey(), $buyer->getKey()]],
    ]);

    expect(ProductReview::where('user_id', $buyer->getKey())->count())
        ->toBe(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(1, raceReport($outputs));

    // The loser must surface the domain exception, not the QueryException
    // underneath it. A raw 1062 reaching a caller is a 500 on a review form.
    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'ReviewNotAllowedException')))
        ->toHaveCount(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'QueryException')))
        ->toBeEmpty(raceReport($outputs));
});
