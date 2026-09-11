<?php

declare(strict_types=1);

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Exceptions\CartAlreadyCheckedOutException;
use App\Exceptions\CouponNotApplicableException;
use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Three races through the full CreateOrder transaction, not through a
 * single composed Action in isolation:
 *
 * - Two customers checking out the last unit of the same variation — the
 *   end-to-end version of ReserveStockConcurrencyTest.
 * - Two customers redeeming a coupon at its total usage limit — the
 *   end-to-end version of RedeemCouponConcurrencyTest, proving composition
 *   inside CreateOrder's larger transaction does not weaken the guarantee
 *   RedeemCoupon already proves alone.
 * - The same cart, checked out twice at once — `orders.cart_id`'s `UNIQUE`
 *   constraint (nullable, no cascade) makes exactly one winner certain
 *   either way; this proves the loser fails cleanly rather than at the
 *   database, the same assertion shape as the first race.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'coupon_redemptions',
        'order_items',
        'order_addresses',
        'orders',
        'cart_items',
        'carts',
        'coupon_product',
        'coupon_product_category',
        'coupons',
        'inventory_movements',
        'inventories',
        'product_variations',
        'product_images',
        'product_specifications',
        'products',
        'product_categories',
        'brands',
    ] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

function orderRaceVariation(int $quantity): ProductVariation
{
    $variation = ProductVariation::factory()->create();

    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $quantity,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    return $variation;
}

function cartWantingOne(ProductVariation $variation, ?int $couponId = null): Cart
{
    $cart = Cart::factory()->create(['user_id' => null, 'coupon_id' => $couponId, 'expires_at' => null]);

    CartItem::factory()->create([
        'cart_id' => $cart->getKey(),
        'product_variation_id' => $variation->getKey(),
        'quantity' => 1,
    ]);

    return $cart;
}

/**
 * Runs one `CreateOrder::handle()` call per cart id, in a separate process
 * each, released together at a shared barrier instant.
 *
 * @param  list<int>  $cartIds
 * @return Collection<int, string>
 */
function runOrderRaceWorkers(array $cartIds): Collection
{
    return runRaceWorkers(array_map(
        fn (int $cartId): array => ['action' => 'create-order', 'ids' => [$cartId]],
        $cartIds,
    ));
}

it('fails the loser of a last-unit checkout race cleanly rather than committing a half order', function (): void {
    $variation = orderRaceVariation(1);
    $cartA = cartWantingOne($variation);
    $cartB = cartWantingOne($variation);

    $outputs = runOrderRaceWorkers([$cartA->getKey(), $cartB->getKey()]);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Neither winning usually means the workers failed to boot.'.$report,
    );

    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.InsufficientStockException::class,
        'The losing checkout did not fail cleanly. A QueryException here means '.
        'it read stale availability and reached chk_inventories_reserved_not_above_current '.
        'instead of ReserveStock refusing it — check CreateOrder still calls '.
        'ReserveStock inside its own transaction.'.$report,
    );

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->reserved_quantity)->toBe(1)
        ->and($inventory->available())->toBe(0)
        ->and(Order::count())->toBe(1)
        ->and(OrderItem::count())->toBe(1);
});

it('fails the loser of a coupon-limit checkout race cleanly, proving RedeemCoupon composed inside CreateOrder still holds', function (): void {
    // Plenty of stock on both sides — the coupon's total_usage_limit is the
    // only thing meant to be contested here, not availability.
    $variationA = orderRaceVariation(10);
    $variationB = orderRaceVariation(10);

    $coupon = Coupon::factory()->create([
        'code' => 'RACE'.bin2hex(random_bytes(6)),
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '5.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => 1,
        'usage_limit_per_customer' => null,
    ]);

    $cartA = cartWantingOne($variationA, $coupon->getKey());
    $cartB = cartWantingOne($variationB, $coupon->getKey());

    $outputs = runOrderRaceWorkers([$cartA->getKey(), $cartB->getKey()]);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Two means both processes read the '.
        'pre-redemption count before either committed — check that '.
        'RedeemCoupon composed inside CreateOrder still lockForUpdate()s '.
        'the coupons row before counting.'.$report,
    );

    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.CouponNotApplicableException::class,
        'The loser did not refuse cleanly.'.$report,
    );

    expect(CouponRedemption::where('coupon_id', $coupon->getKey())->count())->toBe(1)
        ->and(Order::count())->toBe(1);
});

it('fails the loser of a double-submitted checkout cleanly, producing exactly one order', function (): void {
    // Deliberately generous stock: this test is not about availability, it
    // is about whether anything stops the same cart being converted twice.
    // orders.cart_id (UNIQUE, nullable) now does — see
    // write-rules/order.md, "Two actors at once."
    $variation = orderRaceVariation(10);
    $cart = cartWantingOne($variation);

    $outputs = runOrderRaceWorkers([$cart->getKey(), $cart->getKey()]);
    $report = "\nWorker output was:\n".$outputs->implode("\n---\n");

    expect($outputs->filter(fn (string $o) => $o === 'OK'))->toHaveCount(
        1,
        'Expected exactly one winner. Neither winning usually means the workers failed to boot.'.$report,
    );

    expect($outputs->first(fn (string $o) => $o !== 'OK'))->toBe(
        'FAILED:'.CartAlreadyCheckedOutException::class,
        'The loser did not refuse cleanly. A raw QueryException here means '.
        'the UniqueConstraintViolationException catch around the orders '.
        'insert is not doing its job.'.$report,
    );

    expect(Order::count())->toBe(1);
});
