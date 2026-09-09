<?php

declare(strict_types=1);

use App\Contracts\CourierGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CourierUnavailableException;
use App\Facades\Courier;
use App\Models\Carrier;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\CourierTrackingEvent;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use App\Support\Courier\ShipmentResult;
use Carbon\CarbonInterface;
use Database\Seeders\System\CarrierSeeder;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
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

// Lazy over eager: identical isolation guarantee, migrates only on first
// DB touch, so a test that never queries skips it entirely. Measured on
// this suite: 683s -> 617s, 479/479 unchanged, back to back.
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
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
| Browser tests (tests/Browser, ADR-0017) are the other exception. Pest only
| loads this root Pest.php — a Pest.php inside tests/Browser is never read —
| so the browser suite's binding lives here.
|
| pest-plugin-browser boots the application in-process and drives a real
| Chromium against it. A page load is a committed round-trip, so the suite
| uses no refresh trait: it runs against its own database (amazoff_browser,
| set in phpunit.browser.xml), which is migrated once, then has every data
| table truncated and the system reference data reseeded before each test —
| the tests/Concurrency model. Catalogue data is the documented "no real
| creation event" exception and is built with factories inside each spec;
| everything with a real creation path goes through the app's own Actions.
|
| This block is inert for the Feature/Unit/Concurrency runs — tests/Browser
| is not in their testsuites.
*/
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        // Force `@vite` to resolve against the built manifest, never the dev
        // server. pest-plugin-browser's in-process server has no route to
        // Vite on :5173, and if the `vite` compose service is running,
        // `public/hot` exists on the shared mount — `@vite` would then serve
        // raw `resources/css/app.css` (an `@import "tailwindcss"` with no
        // compiled utilities) and every page would render unstyled, making
        // every ResponsiveTest overflow check a false pass. Pointing the hot
        // file at a path that does not exist is env-scoped and touches
        // nothing on disk. `public/build/` must be current — the CI browser
        // job runs `npm run build` first; locally, run it if the styles
        // assertion in ResponsiveTest fails.
        Vite::useHotFile(base_path('storage/framework/testing/vite-no-hot'));

        if (! Schema::hasTable('sessions')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        Schema::disableForeignKeyConstraints();

        // getTableListing() returns schema-qualified names ("amazoff_browser.x")
        // on this MySQL/Laravel 13 combination; DB::table() wants the bare name.
        foreach (Schema::getTableListing() as $qualified) {
            $table = str_contains($qualified, '.') ? explode('.', $qualified, 2)[1] : $qualified;

            if ($table === 'migrations') {
                continue;
            }

            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();

        // Seeders run in-process here — Artisan::call() would reboot the
        // console kernel three times, ~1s per test.
        foreach ([PermissionSeeder::class, RoleSeeder::class, CarrierSeeder::class] as $seeder) {
            test()->seed($seeder);
        }
    })
    ->in('Browser');

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
 * composed Action is rarely the one being tested. See
 * how-to/troubleshooting/ide-and-static-analysis.md, "An authorization test
 * passes with the authorization check deleted".
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
    // No payment_status: it is derived from the payment relation since
    // 2026-08-24, not a column. An order with no payment row reads Pending,
    // which is what this helper used to set explicitly.
    $order = Order::factory()->create(['status' => $status]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variation->product_id,
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return $order->fresh();
}

/*
 * Shared by the returns Action tests (RequestReturn / ReviewReturn /
 * RefundReturn) and RefundReturnConcurrencyTest — Feature and Concurrency are
 * separate suites, so the helper lives here.
 */

/**
 * A delivered order eligible for a return: one line per `$lines`, each against
 * its own variation whose `inventories.sold_quantity` is `$quantity` (so
 * `RestockReturn` has stock to credit back), a `Delivered`
 * `order_status_histories` row stamped `$deliveredAt` (so `Order::deliveredAt()`
 * and the 14-day window resolve), and `anonymized_at` explicitly null (the
 * `OrderFactory` gotcha — its default makes an order look pre-erased).
 */
function deliveredOrderForReturn(
    ?User $customer = null,
    int $quantity = 2,
    int $lines = 1,
    ?CarbonInterface $deliveredAt = null,
    PaymentMethod $paymentMethod = PaymentMethod::Stripe,
    string $unitPrice = '25.00',
): Order {
    $order = Order::factory()->create([
        'user_id' => $customer?->getKey() ?? User::factory(),
        'status' => OrderStatus::Delivered,
        'payment_method' => $paymentMethod,
        'currency' => 'EUR',
        'anonymized_at' => null,
    ]);

    $history = OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => OrderStatus::Shipped,
        'new_status' => OrderStatus::Delivered,
        'user_id' => null,
    ]);
    $history->forceFill(['created_at' => $deliveredAt ?? now()])->save();

    for ($i = 0; $i < $lines; $i++) {
        $variation = ProductVariation::factory()->create([
            'price' => $unitPrice,
            'discount_price' => null,
        ]);

        Inventory::factory()->create([
            'product_variation_id' => $variation->getKey(),
            'current_quantity' => 0,
            'reserved_quantity' => 0,
            'sold_quantity' => $quantity,
            'returned_quantity' => 0,
            'damaged_quantity' => 0,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $variation->product_id,
            'product_variation_id' => $variation->getKey(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => bcmul($unitPrice, (string) $quantity, 2),
            'discount_amount' => 0,
        ]);
    }

    return $order->fresh(['orderItems']);
}

/*
 * Shared by every test that exercises checkout or CreateOrder with a
 * carrier attached — CheckoutTest, CreateOrderTest and
 * CalculateDeliveryPriceTest all need one, and Pest loads every file's
 * top-level declarations into one global scope, so a class or function
 * defined in any one of them would collide if a second file declared its
 * own copy.
 */

/**
 * An in-memory `CourierGateway`. No test may let Econt or Speedy be reached
 * over the network — `swapFakeCourier()` replaces the whole `Courier`
 * facade with this rather than mocking a Saloon connector, because nothing
 * above `CourierManager` should need to know Saloon exists.
 */
class FakeCourierGateway implements CourierGateway
{
    /** @var Collection<int, CourierOffice> */
    public Collection $fakeOffices;

    public string $fakeQuoteAmount = '5.00';

    public int $officesCalls = 0;

    /** Makes the *next* offices() call throw, then reverts to fakeOffices — for a test proving a transient failure is tolerated. */
    public bool $failNextOfficesCall = false;

    public function __construct()
    {
        $this->fakeOffices = collect([
            new CourierOffice(code: 'OFF1', name: 'Test Office 1', address: 'Main St 1', city: 'Sofia', postcode: '1000'),
            new CourierOffice(code: 'OFF2', name: 'Test Office 2', address: 'Main St 2', city: 'Sofia', postcode: '1000'),
        ]);
    }

    public function code(): string
    {
        return 'fake';
    }

    public function cities(string $term): Collection
    {
        return collect();
    }

    public function offices(string $city, ?string $postcode = null): Collection
    {
        $this->officesCalls++;

        if ($this->failNextOfficesCall) {
            $this->failNextOfficesCall = false;

            throw CourierUnavailableException::requestFailed($this->code(), 'offices');
        }

        return $this->fakeOffices;
    }

    public function quote(ShipmentRequest $request): DeliveryQuote
    {
        return new DeliveryQuote(amount: $this->fakeQuoteAmount, currency: 'BGN');
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return new ShipmentResult(shipmentNumber: 'FAKE-1', trackingNumber: 'FAKE-1', labelUrl: null, trackingUrl: null);
    }

    public function label(string $shipmentNumber): string
    {
        return '%PDF-1.4';
    }

    public function track(string $trackingNumber): Collection
    {
        /** @var Collection<int, CourierTrackingEvent> */
        return collect();
    }
}

/**
 * Swaps the `Courier` facade for a fresh `FakeCourierGateway` and returns
 * it, so a test can adjust `fakeQuoteAmount` or `fakeOffices` before acting.
 * Bound into the container the same way `fakeStripeIntents()` binds its
 * Mockery double, rather than as a dynamic property on the test case.
 */
function swapFakeCourier(): FakeCourierGateway
{
    app()->instance(FakeCourierGateway::class, new FakeCourierGateway);

    Courier::swap(new class
    {
        public function for(Carrier $carrier): CourierGateway
        {
            return app(FakeCourierGateway::class);
        }
    });

    return app(FakeCourierGateway::class);
}

/** The `FakeCourierGateway` `swapFakeCourier()` bound, for adjusting mid-test. */
function fakeCourier(): FakeCourierGateway
{
    return app(FakeCourierGateway::class);
}

/**
 * An active carrier. `cod_fee` is pinned to zero so a test that does not
 * care about it gets a deterministic `shipping_amount` — just the
 * `FakeCourierGateway` quote — rather than one of `CarrierFactory`'s three
 * random values.
 */
function checkoutCarrier(array $attributes = []): Carrier
{
    return Carrier::factory()->create(array_merge(['is_active' => true, 'cod_fee' => '0.00'], $attributes));
}
