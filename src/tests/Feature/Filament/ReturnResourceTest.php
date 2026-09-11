<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\ReturnItem;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\StripeClient;

/*
 * The panel surface for the returns aggregate (ADR-0020). Read-only apart
 * from Approve / Deny (ReviewReturn) and Refund (RefundReturn), all driven
 * through the real Actions — a control row proves each button reaches the
 * code and is not a probe that never gets there.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

function panelReturn(ReturnStatus $status = ReturnStatus::Requested): OrderReturn
{
    $order = deliveredOrderForReturn(quantity: 2, paymentMethod: PaymentMethod::CashOnDelivery, unitPrice: '15.00');
    $line = $order->orderItems->first();

    return OrderReturn::factory()
        ->has(ReturnItem::factory()->state(['order_item_id' => $line->id, 'quantity' => 2]), 'returnItems')
        ->create(['order_id' => $order->getKey(), 'status' => $status]);
}

it('is reachable by an administrator', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/admin/returns')
        ->assertOk();
});

it('is not reachable by content_editor or warehouse_employee', function (): void {
    $this->actingAs(User::where('email', 'editor@example.com')->firstOrFail())
        ->get('/admin/returns')->assertForbidden();

    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail())
        ->get('/admin/returns')->assertForbidden();
});

it('approves a return through ReviewReturn', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
    $return = panelReturn();

    Livewire::test(ViewReturn::class, ['record' => $return->getKey()])
        ->callAction('approve', data: ['resolution_note' => 'ok']);

    expect($return->fresh()->status)->toBe(ReturnStatus::Approved);
});

it('denies a return through ReviewReturn', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
    $return = panelReturn();

    Livewire::test(ViewReturn::class, ['record' => $return->getKey()])
        ->callAction('deny', data: ['resolution_note' => 'outside policy']);

    expect($return->fresh()->status)->toBe(ReturnStatus::Denied);
});

it('refunds an approved return through RefundReturn', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    // Stripe-paid order so the refund path is exercised end to end.
    $order = deliveredOrderForReturn(quantity: 2, paymentMethod: PaymentMethod::Stripe, unitPrice: '15.00');
    Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
        'currency' => 'EUR',
        'amount' => '30.00',
        'refunded_amount' => '0.00',
        'stripe_payment_intent_id' => 'pi_panel_1',
    ]);
    $line = $order->orderItems->first();
    $return = OrderReturn::factory()
        ->has(ReturnItem::factory()->state(['order_item_id' => $line->id, 'quantity' => 2]), 'returnItems')
        ->create(['order_id' => $order->getKey(), 'status' => ReturnStatus::Approved]);

    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')->once()->andReturn((object) ['id' => 're_panel']);
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('getService')->with('refunds')->andReturn($refunds);
    app()->instance(StripeClient::class, $client);

    Livewire::test(ViewReturn::class, ['record' => $return->getKey()])
        ->callAction('refund');

    expect($return->fresh()->status)->toBe(ReturnStatus::Refunded);
});

it('offers only the actions legal for the return\'s current status', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    // Requested: review, not refund.
    $requested = panelReturn(ReturnStatus::Requested);
    Livewire::test(ViewReturn::class, ['record' => $requested->getKey()])
        ->assertActionVisible('approve')
        ->assertActionVisible('deny')
        ->assertActionHidden('refund');

    // Refunded: nothing left to do.
    $done = panelReturn(ReturnStatus::Refunded);
    Livewire::test(ViewReturn::class, ['record' => $done->getKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('deny')
        ->assertActionHidden('refund');
});
