<?php

declare(strict_types=1);

use App\Enums\AddressType;
use App\Enums\DeliveryType;
use App\Livewire\Account\OrderDetails;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/*
 * OrderDetails — /account/orders/{order}. Like OrderHistory, the whole
 * point is the scoping: order() starts from auth()->user()->orders() and
 * findOrFail()s, so another customer's id is a 404, not a disclosed row.
 * orders.serial_number is sequential (SEC-002).
 */

it('shows the signed-in customer their own order with a product link and currency', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Product $product */
    $product = Product::factory()->create(['name' => 'Widget', 'slug' => 'widget-x']);
    /** @var Order $order */
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'currency' => 'EUR',
        'total_amount' => '42.00',
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Widget',
        'line_total' => '42.00',
        'quantity' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $order->id])
        ->assertSee($order->serial_number)
        ->assertSee('Widget')
        ->assertSeeHtml('href="'.url('/products/widget-x').'"')
        ->assertSee('€42.00');
});

it('404s for another customer\'s order', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Order $theirs */
    $theirs = Order::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $theirs->id])
        ->assertStatus(404);
});

it('404s for a guest order', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Order $guestOrder */
    $guestOrder = Order::factory()->create(['user_id' => null]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $guestOrder->id])
        ->assertStatus(404);
});

it('requires authentication', function (): void {
    /** @var Order $order */
    $order = Order::factory()->create();

    $this->get('/account/orders/'.$order->id)->assertRedirect('/login');
});

it('shows the delivery address and a tracking placeholder when no number yet', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Order $order */
    $order = Order::factory()->create(['user_id' => $user->id]);
    $order->orderAddresses()->create([
        'type' => AddressType::Delivery,
        'delivery_type' => DeliveryType::Address,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'phone' => '0888',
        'country' => 'BG',
        'city' => 'Sofia',
        'postcode' => '1000',
        'street' => 'Main 1',
    ]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $order->id])
        ->assertSee('Ada Lovelace')
        ->assertSee('Main 1')
        // No shipment row yet.
        ->assertSee('Not shipped yet');
});

it('shows the tracking placeholder once a shipment exists without a number', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Order $order */
    $order = Order::factory()->create(['user_id' => $user->id]);
    App\Models\Shipment::factory()->create([
        'order_id' => $order->id,
        'status' => App\Enums\ShipmentStatus::Pending,
        'tracking_number' => null,
    ]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $order->id])
        ->assertSee('tracking number appears here');
});
