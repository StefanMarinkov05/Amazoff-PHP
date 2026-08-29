<?php

declare(strict_types=1);

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\IllegalOrderStatusTransitionException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

/*
 * ADR-0004 puts legality on the enum, authorization on the policy, recording
 * on the Action. ADR-0011 adds a fourth: the inventory consequence of a
 * transition also lives here, keyed by target status, rather than in a
 * CancelOrder/ShipOrder wrapper a caller could bypass by calling this Action
 * directly.
 *
 * orderWithVariationLine() and variationWithStock() are defined in
 * tests/Pest.php, shared with the inventory Action tests and (for the
 * former) kept distinct in name from RedeemCouponTest's own orderWithLine().
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

/** Stock shaped for whatever `$to` needs, current=10 regardless of quantity. */
function inventoryFor(OrderStatus $to, int $quantity): ProductVariation
{
    return match ($to) {
        OrderStatus::Returned => variationWithStock(current: 10, sold: $quantity),
        default => variationWithStock(current: 10, reserved: $quantity),
    };
}

/*
 * Table-driven off OrderStatus::allowedTransitions() itself, per
 * add-an-action.md step 8 — a matrix edit cannot silently drop coverage the
 * way a hand-written pair list could.
 */
it('completes every legal transition', function (OrderStatus $from, OrderStatus $to): void {
    $quantity = 2;
    $variation = inventoryFor($to, $quantity);
    $order = orderWithVariationLine($variation, $from, $quantity);

    $result = app(TransitionOrderStatus::class)->handle($order, $to, null);

    expect($result->status)->toBe($to)
        ->and($order->fresh()->status)->toBe($to)
        ->and($order->orderStatusHistories()->sole()->new_status)->toBe($to);
})->with(function (): array {
    $pairs = [];

    foreach (OrderStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $pairs["{$from->value} => {$to->value}"] = [$from, $to];
        }
    }

    return $pairs;
});

it('refuses an illegal transition and writes nothing', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);

    expect(fn () => app(TransitionOrderStatus::class)->handle($order, OrderStatus::Delivered, null))
        ->toThrow(IllegalOrderStatusTransitionException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::New)
        ->and($order->orderStatusHistories()->count())->toBe(0)
        ->and(Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity)->toBe(2);
});

it('refuses to walk an order backwards and writes nothing', function (): void {
    $variation = variationWithStock(current: 10, sold: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Delivered, 2);

    expect(fn () => app(TransitionOrderStatus::class)->handle($order, OrderStatus::New, null))
        ->toThrow(IllegalOrderStatusTransitionException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->orderStatusHistories()->count())->toBe(0);
});

it('writes exactly one history row with every §19 field', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('updateStatus_order');

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Confirmed, $actor, 'stock confirmed', 'checked warehouse A');

    /** @var OrderStatusHistory $history */
    $history = $order->orderStatusHistories()->sole();

    expect($history->previous_status)->toBe(OrderStatus::New)
        ->and($history->new_status)->toBe(OrderStatus::Confirmed)
        ->and($history->user_id)->toBe($actor->getKey())
        ->and($history->reason)->toBe('stock confirmed')
        ->and($history->note)->toBe('checked warehouse A');
});

/*
 * The no-op — a double-submitted transition, or a retried job. Safe only
 * because OrderStatus's graph is acyclic (TransitionMatrixTest), so
 * $from === $to unambiguously means "already done" rather than "here for a
 * legitimate second time."
 */

it('is a clean no-op when the order is already at the target status', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Confirmed, 2);
    Event::fake();

    $result = app(TransitionOrderStatus::class)->handle($order, OrderStatus::Confirmed, null);

    expect($result->status)->toBe(OrderStatus::Confirmed)
        ->and($order->orderStatusHistories()->count())->toBe(0)
        ->and(Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity)->toBe(2);

    Event::assertNotDispatched(OrderStatusChanged::class);
});

it('moves no stock on a no-op even for the inventory-effect statuses', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Cancelled, 2);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, null);

    // A second "cancel" on an already-cancelled order must not release the
    // same reservation twice — reserved_quantity is untouched, not driven
    // negative or double-released.
    expect(Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity)->toBe(2);
});

it('still denies a no-op to an unauthorized actor', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Cancelled, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('updateStatus_order'); // not cancel_order

    // A denied actor must see AuthorizationException even when the move is
    // already done — a silent success here would leak whether the move
    // would otherwise have been permitted.
    expect(fn () => app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, $actor))
        ->toThrow(AuthorizationException::class);
});

/*
 * ADR-0007: null means the application acting on its own behalf and skips
 * the policy check.
 */

it('skips the policy for a null actor', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);

    $result = app(TransitionOrderStatus::class)->handle($order, OrderStatus::Confirmed, null);

    expect($result->status)->toBe(OrderStatus::Confirmed);
});

it('denies an actor without updateStatus_order and writes nothing', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $actor = User::factory()->create();

    expect(fn () => app(TransitionOrderStatus::class)->handle($order, OrderStatus::Confirmed, $actor))
        ->toThrow(AuthorizationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::New)
        ->and($order->orderStatusHistories()->count())->toBe(0);
});

it('allows an actor holding updateStatus_order for a routine advance', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('updateStatus_order');

    $result = app(TransitionOrderStatus::class)->handle($order, OrderStatus::Confirmed, $actor);

    expect($result->status)->toBe(OrderStatus::Confirmed);
});

/*
 * ADR-0011's routing: cancelling and refunding are administrator moves,
 * distinct from updateStatus_order's routine advances. §18.
 */

it('denies cancel_order-less actor a cancellation despite holding updateStatus_order', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('updateStatus_order');

    expect(fn () => app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, $actor))
        ->toThrow(AuthorizationException::class);

    expect(Inventory::where('product_variation_id', $variation->getKey())->sole()->reserved_quantity)->toBe(2);
});

it('allows an actor holding cancel_order to cancel', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('cancel_order');

    $result = app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, $actor);

    expect($result->status)->toBe(OrderStatus::Cancelled);
});

it('denies refund_order-less actor a refund despite holding updateStatus_order', function (): void {
    $variation = variationWithStock(current: 10, sold: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Returned, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('updateStatus_order');

    expect(fn () => app(TransitionOrderStatus::class)->handle($order, OrderStatus::Refunded, $actor))
        ->toThrow(AuthorizationException::class);
});

it('allows an actor holding refund_order to refund', function (): void {
    $variation = variationWithStock(current: 10, sold: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Returned, 2);
    $actor = User::factory()->create();
    $actor->givePermissionTo('refund_order');

    $result = app(TransitionOrderStatus::class)->handle($order, OrderStatus::Refunded, $actor);

    expect($result->status)->toBe(OrderStatus::Refunded);
});

it("holds warehouse_employee's real, seeded permission boundary", function (): void {
    $this->seed(RoleSeeder::class);

    $warehouse = User::factory()->create();
    $warehouse->assignRole('warehouse_employee');

    $reservedVariation = variationWithStock(current: 10, reserved: 2);
    $reservedOrder = orderWithVariationLine($reservedVariation, OrderStatus::New, 2);

    // The routine advance §3.4 grants.
    $result = app(TransitionOrderStatus::class)->handle($reservedOrder, OrderStatus::Confirmed, $warehouse);
    expect($result->status)->toBe(OrderStatus::Confirmed);

    // Cancelling and refunding are administrator moves per ADR-0011 —
    // warehouse_employee's seeded grant list does not include either.
    $cancelVariation = variationWithStock(current: 10, reserved: 2);
    $cancelOrder = orderWithVariationLine($cancelVariation, OrderStatus::New, 2);

    expect(fn () => app(TransitionOrderStatus::class)->handle($cancelOrder, OrderStatus::Cancelled, $warehouse))
        ->toThrow(AuthorizationException::class);

    $refundVariation = variationWithStock(current: 10, sold: 2);
    $refundOrder = orderWithVariationLine($refundVariation, OrderStatus::Returned, 2);

    expect(fn () => app(TransitionOrderStatus::class)->handle($refundOrder, OrderStatus::Refunded, $warehouse))
        ->toThrow(AuthorizationException::class);
});

/*
 * Events — ADR-0007: ShouldDispatchAfterCommit is a property of the class,
 * asserted here as "the Action calls dispatch()" rather than "commit
 * ordering holds", which Event::fake() cannot observe either way.
 */

it('dispatches OrderStatusChanged exactly once with the right from and to', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);
    Event::fake();

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Confirmed, null);

    Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->from === OrderStatus::New && $event->to === OrderStatus::Confirmed);
});

/*
 * ADR-0011's effect table — one test per target that carries a consequence,
 * plus one that confirms a routine advance carries none.
 */

it('releases stock on cancellation', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::New, 2);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, null);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->current_quantity)->toBe(10)
        ->and($inventory->available())->toBe(10);
});

it('completes the sale on shipment, moving reserved stock to sold', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::ReadyForShipment, 2);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Shipped, null);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->sold_quantity)->toBe(2)
        ->and($inventory->current_quantity)->toBe(8);
});

it('restocks a return, moving sold stock back to current', function (): void {
    $variation = variationWithStock(current: 8, sold: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Delivered, 2);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Returned, null);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->sold_quantity)->toBe(0)
        ->and($inventory->returned_quantity)->toBe(2)
        ->and($inventory->current_quantity)->toBe(10);
});

it('moves no inventory on a status with no defined effect', function (): void {
    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::Confirmed, 2);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Preparing, null);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();
    expect($inventory->reserved_quantity)->toBe(2)
        ->and($inventory->sold_quantity)->toBe(0)
        ->and($inventory->current_quantity)->toBe(10);
});

it('applies the inventory effect to every line of a multi-line order', function (): void {
    $variationA = variationWithStock(current: 10, reserved: 3);
    $variationB = variationWithStock(current: 5, reserved: 2);

    $order = Order::factory()->create(['status' => OrderStatus::New]);

    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variationA->product_id,
        'product_variation_id' => $variationA->getKey(),
        'quantity' => 3,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_id' => $variationB->product_id,
        'product_variation_id' => $variationB->getKey(),
        'quantity' => 2,
    ]);

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, null);

    expect(Inventory::where('product_variation_id', $variationA->getKey())->sole()->reserved_quantity)->toBe(0)
        ->and(Inventory::where('product_variation_id', $variationB->getKey())->sole()->reserved_quantity)->toBe(0);
});
