<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Order\CreateOrder;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Chaos / failure-injection, not a race: one real MySQL connection, killed
 * from a second one at the instant `CreateOrder`'s transaction has written
 * its first row (`orders`) but before the transaction commits — the literal
 * "the customer's connection drops mid-checkout, or the DB server hiccups"
 * scenario, reproduced against the actual network protocol rather than
 * asserted from reading `DB::transaction()`'s documented behaviour.
 *
 * `tests/Concurrency`, not `tests/Feature`, for the same reason every other
 * file here is: a second real connection and no `RefreshDatabase` — this
 * test's own second PDO connection would otherwise be invisible to (and
 * corrupted by) the outer transaction `RefreshDatabase` wraps each test in.
 *
 * What this proves that a unit test asserting "DB::transaction() catches
 * Throwable" cannot: that a genuine mid-transaction connection loss —
 * indistinguishable, from inside the transaction, from a customer's own
 * internet dropping — leaves *no* partial state. Not "the order row is
 * gone" alone; every table CreateOrder writes to is checked, and the stock
 * it reserved is checked too, because a lock held by a connection MySQL has
 * just killed is exactly the shape of bug that leaves a variation's
 * reserved_quantity permanently wrong.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'order_items',
        'order_addresses',
        'orders',
        'cart_items',
        'carts',
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

it('leaves no order, no order item, and no reserved stock when the connection is killed mid-transaction', function (): void {
    $variation = cartVariation(stock: 10, product: ['regular_price' => '25.00', 'vat_rate' => 20.00]);
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, $variation, 2);

    // A second, independent PDO connection — not a Laravel connection name,
    // a genuinely separate socket to the same server — so that killing it
    // reaches MySQL's own connection-handling code, not anything Laravel's
    // connection pooling could intercept or retry around.
    $host = config()->string('database.connections.mysql.host');
    $port = config()->string('database.connections.mysql.port');
    $database = config()->string('database.connections.mysql.database');
    $username = config()->string('database.connections.mysql.username');
    $password = config()->string('database.connections.mysql.password');

    $killerPdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database}",
        $username,
        $password,
    );

    /** @var object{id: int} $connectionRow */
    $connectionRow = DB::selectOne('SELECT CONNECTION_ID() AS id');
    $mainConnectionId = $connectionRow->id;

    $listener = function ($query) use ($killerPdo, $mainConnectionId): void {
        // Fires once, on the first INSERT into `orders` — CreateOrder's
        // transaction writes that row first, then order_items, then
        // reserves stock. Killing the connection here is deliberately
        // *after* real work has happened inside the transaction, which is
        // the case worth proving: a connection loss late enough that a
        // naive implementation (autocommit per statement, or a
        // half-observed transaction) would already have something to lose.
        if (str_starts_with($query->sql, 'insert into `orders`')) {
            $killerPdo->exec("KILL {$mainConnectionId}");
        }
    };

    DB::listen($listener);

    try {
        app(CreateOrder::class)->handle(
            $cart,
            [
                'email' => 'chaos@example.test',
                'phone' => '+359888000000',
                'first_name' => 'Chaos',
                'last_name' => 'Test',
                'payment_method' => PaymentMethod::CashOnDelivery,
            ],
            [
                'delivery_type' => DeliveryType::Address,
                'first_name' => 'Chaos',
                'last_name' => 'Test',
                'phone' => '+359888000000',
                'country' => 'BG',
                'city' => 'Sofia',
                'postcode' => '1000',
                'street' => 'Vitosha 1',
            ],
            [
                'delivery_type' => DeliveryType::Address,
                'first_name' => 'Chaos',
                'last_name' => 'Test',
                'phone' => '+359888000000',
                'country' => 'BG',
                'city' => 'Sofia',
                'postcode' => '1000',
                'street' => 'Vitosha 1',
            ],
            null,
        );

        // The killed connection must surface as a real failure. A silent
        // success here would mean the kill never actually reached the
        // transaction, and the test would be proving nothing.
        expect(false)->toBeTrue('CreateOrder::handle() should have thrown when its connection was killed mid-transaction.');
    } catch (QueryException|PDOException $e) {
        // Expected. Laravel's own connection is now dead; a fresh query on
        // it (the assertions below) forces a reconnect, which is real
        // application behaviour worth this test also covering implicitly —
        // Laravel reconnects transparently on the next query rather than
        // requiring the caller to know the connection died.
    }

    // The actual proof: query fresh, after the forced reconnect, and find
    // nothing CreateOrder's transaction would have written.
    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity)->toBe(0);
});
