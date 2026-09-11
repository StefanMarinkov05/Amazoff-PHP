<?php

declare(strict_types=1);

use App\Actions\Returns\RefundReturn;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\ReturnNotAllowedException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\ReturnItem;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;
use Stripe\StripeClient;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(PermissionSeeder::class);
});

/*
 * RefundReturn — money back, stock back (ADR-0020). Stripe is faked: what is
 * under test is this application's composition (goods total from the frozen
 * order line, RestockReturn per return item, the COD branch), not that
 * Stripe works. Mirrors StripePaymentTest's approach.
 */

function fakeStripeRefunds(): object
{
    $refunds = Mockery::mock();
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('getService')->with('refunds')->andReturn($refunds);
    app()->instance(StripeClient::class, $client);

    return $refunds;
}

/**
 * An approved return of `$qty` of the given order's single line.
 */
function approvedReturn(Order $order, int $qty = 2): OrderReturn
{
    $line = $order->orderItems->first();

    return OrderReturn::factory()
        ->has(ReturnItem::factory()->state(['order_item_id' => $line->id, 'quantity' => $qty]), 'returnItems')
        ->create(['order_id' => $order->getKey(), 'status' => ReturnStatus::Approved]);
}

function returnRefunder(): User
{
    $user = User::factory()->create();
    // RefundReturn gates the returns workflow on refund_return and passes the
    // actor through to RefundPayment, which gates the disbursement on
    // refund_payment — moving money needs the money permission too (ADR-0020).
    $user->givePermissionTo(['refund_return', 'refund_payment']);

    return $user;
}

it('refunds the goods value through Stripe and restocks the items', function (): void {
    $order = deliveredOrderForReturn(quantity: 3, paymentMethod: PaymentMethod::Stripe, unitPrice: '25.00');
    $payment = Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
        'currency' => 'EUR',
        'amount' => '75.00',
        'refunded_amount' => '0.00',
        'stripe_payment_intent_id' => 'pi_test_1',
    ]);

    $captured = null;
    $refunds = fakeStripeRefunds();
    $refunds->shouldReceive('create')->once()->andReturnUsing(function (array $params) use (&$captured) {
        $captured = $params;

        return (object) ['id' => 're_1'];
    });

    $return = approvedReturn($order, 2);
    $variation = $order->orderItems->first()->productVariation;

    $result = app(RefundReturn::class)->handle($return, returnRefunder());

    // 2 × 25.00 = 50.00 → 5000 minor units.
    expect($captured['amount'])->toBe(5000)
        ->and($result->status)->toBe(ReturnStatus::Refunded)
        ->and((string) $result->refunded_amount)->toBe('50.00')
        ->and((string) $payment->fresh()->refunded_amount)->toBe('50.00');

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->first();
    expect($inventory->sold_quantity)->toBe(1)
        ->and($inventory->current_quantity)->toBe(2)
        ->and($inventory->returned_quantity)->toBe(2);
});

it('marks a cash-on-delivery return refunded with an offline note and still restocks', function (): void {
    $order = deliveredOrderForReturn(quantity: 2, paymentMethod: PaymentMethod::CashOnDelivery, unitPrice: '10.00');
    // COD order — no payment row at all.
    $return = approvedReturn($order, 2);
    $variation = $order->orderItems->first()->productVariation;

    // If it reaches Stripe this mock has no `create` expectation and fails.
    fakeStripeRefunds();

    $result = app(RefundReturn::class)->handle($return, returnRefunder());

    expect($result->status)->toBe(ReturnStatus::Refunded)
        ->and((string) $result->refunded_amount)->toBe('20.00')
        ->and($result->resolution_note)->toContain('offline');

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->first();
    expect($inventory->returned_quantity)->toBe(2)
        ->and($inventory->sold_quantity)->toBe(0);
});

it('accumulates across two returns on one order without exceeding the payment', function (): void {
    $order = deliveredOrderForReturn(quantity: 4, unitPrice: '25.00');
    Payment::factory()->create([
        'order_id' => $order->getKey(),
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
        'currency' => 'EUR',
        'amount' => '100.00',
        'refunded_amount' => '0.00',
        'stripe_payment_intent_id' => 'pi_test_2',
    ]);

    $refunds = fakeStripeRefunds();
    $refunds->shouldReceive('create')->twice()->andReturn((object) ['id' => 're']);

    app(RefundReturn::class)->handle(approvedReturn($order, 2), returnRefunder());
    app(RefundReturn::class)->handle(approvedReturn($order->fresh(['orderItems']), 2), returnRefunder());

    expect((string) $order->payment->fresh()->refunded_amount)->toBe('100.00')
        ->and($order->payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('refuses to refund a return that is not approved', function (): void {
    $order = deliveredOrderForReturn();
    $return = approvedReturn($order);
    $return->update(['status' => ReturnStatus::Requested]);

    fakeStripeRefunds();

    expect(fn () => app(RefundReturn::class)->handle($return, returnRefunder()))
        ->toThrow(ReturnNotAllowedException::class);
});

it('requires refund_return', function (): void {
    $order = deliveredOrderForReturn();
    $return = approvedReturn($order);

    fakeStripeRefunds();

    expect(fn () => app(RefundReturn::class)->handle($return, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    expect($return->fresh()->status)->toBe(ReturnStatus::Approved);
});
