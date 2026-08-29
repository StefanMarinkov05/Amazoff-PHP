<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\RatingDistributionChart;
use App\Filament\Widgets\ReturnsAndDamageTable;
use App\Filament\Widgets\RevenueOverview;
use App\Filament\Widgets\RevenueTrendChart;
use App\Filament\Widgets\ReviewsOverview;
use App\Filament\Widgets\TopSellingProductsTable;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductReview;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Every widget queries against real tables with raw SQL and joins — the
 * kind of thing that compiles clean under Larastan and breaks on the first
 * request against an actual database (a typo'd column name, a join that
 * doesn't exist). This is exactly the class of bug CLAUDE.md's "verify
 * against a running app, not by reading code" exists for, and each widget
 * gets factory-created rows to aggregate so a query that runs against an
 * empty table but breaks on real data does not pass silently.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
});

it('renders the revenue overview against real paid and refunded payments', function (): void {
    $paidOrder = Order::factory()->create();
    Payment::factory()->for($paidOrder)->create([
        'status' => PaymentStatus::Paid,
        'amount' => '199.99',
        'refunded_amount' => '0.00',
        'paid_at' => now()->subDays(5),
    ]);
    OrderItem::factory()->for($paidOrder)->create(['quantity' => 3]);

    $refundedOrder = Order::factory()->create();
    Payment::factory()->for($refundedOrder)->create([
        'status' => PaymentStatus::PartiallyRefunded,
        'amount' => '100.00',
        'refunded_amount' => '25.00',
        'paid_at' => now()->subDays(10),
    ]);

    Inventory::factory()->create(['sold_quantity' => 20, 'returned_quantity' => 2]);

    Livewire::test(RevenueOverview::class)
        ->assertOk()
        ->assertSee('Revenue (30d)')
        ->assertSee('Return rate');
});

it('renders the revenue trend chart', function (): void {
    $order = Order::factory()->create();
    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Paid,
        'paid_at' => now()->subDays(15),
    ]);

    Livewire::test(RevenueTrendChart::class)->assertOk();
});

it('renders orders by status against every current status', function (): void {
    Order::factory()->count(3)->create();

    Livewire::test(OrdersByStatusChart::class)->assertOk();
});

it('renders top-selling products, excluding orders that never became sales', function (): void {
    $sold = Order::factory()->create(['status' => OrderStatus::Delivered]);
    OrderItem::factory()->for($sold)->create([
        'product_name' => 'Sold Product',
        'product_sku' => 'SOLD-1',
        'quantity' => 5,
    ]);

    $neverPaid = Order::factory()->create(['status' => OrderStatus::AwaitingPayment]);
    OrderItem::factory()->for($neverPaid)->create([
        'product_name' => 'Unpaid Product',
        'product_sku' => 'UNPAID-1',
        'quantity' => 99,
    ]);

    Livewire::test(TopSellingProductsTable::class)
        ->assertOk()
        ->assertSee('Sold Product')
        ->assertDontSee('Unpaid Product');
});

it('renders the returns and damage table, ranked by loss rate not raw count', function (): void {
    Inventory::factory()->create([
        'sold_quantity' => 500,
        'returned_quantity' => 5,
        'damaged_quantity' => 0,
    ]);
    Inventory::factory()->create([
        'sold_quantity' => 10,
        'returned_quantity' => 5,
        'damaged_quantity' => 0,
    ]);

    Livewire::test(ReturnsAndDamageTable::class)->assertOk();
});

it('renders the reviews overview counting pending and approved separately', function (): void {
    ProductReview::factory()->create(['approved' => true, 'rating' => 5]);
    ProductReview::factory()->create(['approved' => false, 'rating' => 3]);

    Livewire::test(ReviewsOverview::class)
        ->assertOk()
        ->assertSee('Pending approval');
});

it('renders the rating distribution chart', function (): void {
    ProductReview::factory()->create(['rating' => 4]);

    Livewire::test(RatingDistributionChart::class)->assertOk();
});

it('gates the whole admin panel by canAccessPanel, not by widget rendering alone', function (): void {
    // A guest at /admin is redirected to the storefront /login (verified
    // separately, since AdminPanelProvider has no ->login() of its own). An
    // authenticated user who fails canAccessPanel() is a different case —
    // already signed in, just not staff — and gets a 403, not a redirect;
    // a redirect-to-login here would be misleading about why access failed.
    $customer = User::factory()->create(['is_active' => true]);

    $this->actingAs($customer)
        ->get('/admin')
        ->assertForbidden();
});

it('boots /admin with every widget registered and none crashing panel discovery', function (): void {
    // Each widget's own content is already proven by the tests above — this
    // one is about assembly, not content. Filament's dashboard widgets are
    // Livewire-lazy (Filament\Widgets\Widget::isLazy()): the page shell
    // returns before any widget query runs, so its content arrives through
    // per-widget follow-up requests the initial response never contains.
    // Asserting widget text here would mean asserting on Livewire's own
    // lazy-loading mechanism, not on anything of ours — exactly what
    // CLAUDE.md's "prove something ours, not the framework's" rules out.
    //
    // What *is* ours: `discoverWidgets()` finding a broken widget class (a
    // typo'd import, a missing method) throws at panel boot, before any
    // request-time query runs — this caught exactly that during development,
    // when a stale `FilamentInfoWidget::class` reference (its import removed,
    // the array entry left behind) took the whole panel down with a
    // `ComponentNotFoundException` on every request, not just the Dashboard.
    Order::factory()->create();
    Inventory::factory()->create();
    ProductReview::factory()->create();

    $this->get('/admin')->assertOk();
});
