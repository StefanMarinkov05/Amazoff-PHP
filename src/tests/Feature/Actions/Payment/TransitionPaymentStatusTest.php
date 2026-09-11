<?php

declare(strict_types=1);

use App\Actions\Payment\TransitionPaymentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalPaymentStatusTransitionException;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * The single writer of `payments.status` and of the money a status change
 * implies.
 *
 * The case that earns this file its place is the self-transition:
 * PaymentStatus is NOT acyclic - PartiallyRefunded lists itself - so unlike
 * TransitionOrderStatus this Action deliberately has no `$from === $to`
 * no-op shortcut. Two partial refunds are two real events, and a shortcut
 * would silently swallow the second. Everything else here guards the money.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

function paymentAt(PaymentStatus $status, string $amount = '100.00', string $refunded = '0.00'): Payment
{
    return Payment::factory()->create([
        'status' => $status,
        'amount' => $amount,
        'refunded_amount' => $refunded,
        'paid_at' => null,
    ]);
}

it('stamps paid_at on arrival at paid', function (): void {
    $payment = paymentAt(PaymentStatus::Pending);

    $moved = app(TransitionPaymentStatus::class)->handle($payment, PaymentStatus::Paid, null);

    expect($moved->status)->toBe(PaymentStatus::Paid)
        ->and($moved->paid_at)->not->toBeNull();
});

it('accumulates a second partial refund rather than replacing the first', function (): void {
    // The highest-value case in this file. A no-op shortcut on $from === $to
    // - which TransitionOrderStatus legitimately has, because OrderStatus is
    // acyclic - would return early here and lose the second refund entirely,
    // leaving refunded_amount at 20.00 with nothing reporting a problem.
    $payment = paymentAt(PaymentStatus::Paid);

    $first = app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::PartiallyRefunded, null, '20.00');

    expect((string) $first->refunded_amount)->toBe('20.00');

    $second = app(TransitionPaymentStatus::class)
        ->handle($first, PaymentStatus::PartiallyRefunded, null, '30.00');

    expect((string) $second->refunded_amount)->toBe('50.00')
        ->and($second->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('refuses a partial refund that would exceed the payment, and writes nothing', function (): void {
    $payment = paymentAt(PaymentStatus::Paid, amount: '100.00');

    app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::PartiallyRefunded, null, '90.00');

    expect(fn () => app(TransitionPaymentStatus::class)
        ->handle($payment->refresh(), PaymentStatus::PartiallyRefunded, null, '20.00'))
        ->toThrow(InvalidArgumentException::class);

    // The refusal has to leave the earlier refund intact - a partial write
    // here would be worse than the refusal it replaced.
    expect((string) $payment->refresh()->refunded_amount)->toBe('90.00');
});

it('treats a full refund as the whole amount without being told it', function (): void {
    $payment = paymentAt(PaymentStatus::Paid, amount: '249.90');

    $moved = app(TransitionPaymentStatus::class)->handle($payment, PaymentStatus::Refunded, null);

    expect((string) $moved->refunded_amount)->toBe('249.90');
});

it('refuses an amount on a status that is not a refund', function (): void {
    $payment = paymentAt(PaymentStatus::Pending);

    expect(fn () => app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::Paid, null, '10.00'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a partial refund with no amount', function (): void {
    $payment = paymentAt(PaymentStatus::Paid);

    expect(fn () => app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::PartiallyRefunded, null))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a move the matrix does not allow', function (): void {
    // Paid => Pending is backwards; PaymentStatus::Paid lists only the two
    // refund statuses.
    $payment = paymentAt(PaymentStatus::Paid);

    expect(fn () => app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::Pending, null))
        ->toThrow(IllegalPaymentStatusTransitionException::class);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
});

it('routes a refund to refund_payment rather than update_payment', function (): void {
    // The distinction this asserts is the Action's: refunding money is not
    // editing a row, so PaymentPolicy gates it separately. An actor holding
    // update_payment alone must be refused the refund - and holding it is
    // what makes the test prove the refund gate specifically, rather than
    // being denied by whichever check happens to run first.
    $updater = User::factory()->create();
    $updater->givePermissionTo('update_payment');

    $payment = paymentAt(PaymentStatus::Paid);

    expect(fn () => app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::PartiallyRefunded, $updater, '10.00'))
        ->toThrow(AuthorizationException::class);

    expect((string) $payment->refresh()->refunded_amount)->toBe('0.00');
});

it('allows a non-refund move for an actor holding only update_payment', function (): void {
    $updater = User::factory()->create();
    $updater->givePermissionTo('update_payment');

    $payment = paymentAt(PaymentStatus::Pending);

    $moved = app(TransitionPaymentStatus::class)->handle($payment, PaymentStatus::Paid, $updater);

    expect($moved->status)->toBe(PaymentStatus::Paid);
});

it('allows a refund for an actor holding refund_payment', function (): void {
    $refunder = User::factory()->create();
    $refunder->givePermissionTo('refund_payment');

    $payment = paymentAt(PaymentStatus::Paid);

    $moved = app(TransitionPaymentStatus::class)
        ->handle($payment, PaymentStatus::PartiallyRefunded, $refunder, '25.00');

    expect((string) $moved->refunded_amount)->toBe('25.00');
});
