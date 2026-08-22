<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Concurrency tests are the exception, and must not use RefreshDatabase.
|
| It wraps each test in a transaction that is rolled back rather than
| committed, so rows the test creates are invisible to every other database
| connection — and a second connection asking for them blocks on the test's
| own uncommitted write. A test for row locking then times out on its own
| first session and proves nothing about the code under test.
|
| These tests commit their fixtures and clean up after themselves.
*/
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * Shared by the catalogue Action tests. Defined here rather than in whichever
 * test file happened to need them first: Pest exposes a test file's functions
 * globally only once that file is loaded, so a helper living in a sibling is
 * undefined when its consumer is run on its own with a path or --filter.
 */

/**
 * Grants real permission names from the real catalogue. givePermissionTo()
 * throws on a name that does not exist, so a typo fails loudly instead of
 * granting nothing and letting a denial test pass for the wrong reason.
 *
 * Grant everything the operation needs *except* the permission under test —
 * an actor holding none is denied by whichever check runs first, which for a
 * composed Action is rarely the one being tested. See troubleshooting.md,
 * "An authorization test passes with the authorization check deleted".
 */
function catalogueActor(string ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array<string, mixed> */
function variationAttributes(array $overrides = []): array
{
    return array_merge([
        'sku' => fake()->unique()->regexify('[A-Z0-9]{16}'),
        'price' => '19.99',
        'is_available' => true,
    ], $overrides);
}

/*
 * Shared by the cart Action tests. Same reason as above: a helper defined in a
 * sibling test file is undefined when its consumer runs under --filter.
 */

/**
 * A variation with a stock row, at prices and limits the test states.
 *
 * ProductFactory randomises `regular_price`, `discount_price`, the discount
 * window, `min_order_quantity`, and `is_available`; ProductVariationFactory
 * randomises the price overrides and pulls in a ProductImage. Every one of
 * those is an input to the rules under test, so a cart test built on the
 * defaults asserts against a different product on each run.
 *
 * @param  array<string, mixed>  $product
 * @param  array<string, mixed>  $variation
 */
function cartVariation(int $stock = 10, array $product = [], array $variation = []): ProductVariation
{
    $productModel = Product::factory()->create(array_merge([
        'regular_price' => '100.00',
        'discount_price' => null,
        'discount_starts_at' => null,
        'discount_ends_at' => null,
        'vat_rate' => 20.00,
        'min_order_quantity' => 1,
        'is_available' => true,
    ], $product));

    $variationModel = ProductVariation::factory()->create(array_merge([
        'product_id' => $productModel->getKey(),
        // Null rather than the factory's ProductImage: that image belongs to a
        // second product the test never names, and this one needs no image.
        'image_id' => null,
        'price' => null,
        'discount_price' => null,
        'is_available' => true,
    ], $variation));

    Inventory::factory()->create([
        'product_variation_id' => $variationModel->getKey(),
        'current_quantity' => $stock,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
        'returned_quantity' => 0,
        'damaged_quantity' => 0,
    ]);

    return $variationModel;
}

/**
 * A cart with neither an owner nor a coupon — both nullable, and the factory
 * creates a User and a Coupon for every cart otherwise.
 */
function emptyCart(?User $owner = null): Cart
{
    return Cart::factory()->create([
        'user_id' => $owner?->getKey(),
        'coupon_id' => null,
        'expires_at' => null,
    ]);
}

/*
 * Shared by the inventory Action tests — ReserveStock, ReleaseStock,
 * CompleteSale, RestockReturn. Same reason as catalogueActor(): defined here
 * rather than in whichever sibling file needed it first, so it is not
 * undefined when one of the four test files runs alone under --filter.
 */

/**
 * A variation with a stock row at exactly the counters a test states —
 * nothing randomised, since every one of the four counters is an input to
 * the rule under test.
 */
function variationWithStock(int $current, int $reserved = 0, int $sold = 0, int $returned = 0): ProductVariation
{
    $variation = ProductVariation::factory()->create();

    Inventory::factory()->create([
        'product_variation_id' => $variation->getKey(),
        'current_quantity' => $current,
        'reserved_quantity' => $reserved,
        'sold_quantity' => $sold,
        'returned_quantity' => $returned,
        'damaged_quantity' => 0,
    ]);

    return $variation;
}

/*
 * Shared by the order-status Action tests — TransitionOrderStatusTest and
 * its concurrency counterpart both need an order with a real line pointing
 * at a real stock row, and the two files run as separate Pest suites
 * (Feature vs Concurrency), so neither can define this for the other.
 */

/**
 * An order with one line against `$variation`, at whatever `OrderStatus` the
 * test needs. Deliberately does not touch `$variation`'s inventory row —
 * New/Confirmed/Shipped mean different things for reserved/sold/current, and
 * a helper that guessed would hide the exact precondition each test means to
 * state. Callers set the inventory counters themselves, right next to the
 * status they chose, per `variationWithStock()` above.
 *
 * Named `orderWithVariationLine`, not `orderWithLine` — that name is already
 * a distinct, file-local helper in `RedeemCouponTest.php`, and Pest loads
 * every file's functions into one global scope.
 */
function orderWithVariationLine(ProductVariation $variation, OrderStatus $status, int $quantity = 2): Order
{
    $order = Order::factory()->create([
        'status' => $status,
        'payment_status' => PaymentStatus::Pending,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return $order->fresh();
}
