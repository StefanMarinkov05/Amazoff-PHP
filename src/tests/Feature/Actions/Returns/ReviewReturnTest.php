<?php

declare(strict_types=1);

use App\Actions\Returns\ReviewReturn;
use App\Enums\ReturnStatus;
use App\Exceptions\IllegalReturnStatusTransitionException;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(PermissionSeeder::class);
});

/*
 * ReviewReturn — staff approve or deny (ADR-0020). The `TransitionOrderStatus`
 * pattern: legality on the enum, authorization on the policy, the status
 * re-read under the lock.
 */

function requestedReturn(): OrderReturn
{
    $order = deliveredOrderForReturn();
    $line = $order->orderItems->first();

    return OrderReturn::factory()
        ->has(ReturnItem::factory()->state(['order_item_id' => $line->id, 'quantity' => 1]), 'returnItems')
        ->create(['order_id' => $order->getKey(), 'status' => ReturnStatus::Requested]);
}

function returnReviewer(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('update_return');

    return $user;
}

it('approves a requested return with a note', function (): void {
    $return = requestedReturn();

    $result = app(ReviewReturn::class)->handle($return, ReturnStatus::Approved, 'Looks fine', returnReviewer());

    expect($result->status)->toBe(ReturnStatus::Approved)
        ->and($result->resolution_note)->toBe('Looks fine')
        ->and($result->resolved_at)->not->toBeNull();
});

it('denies a requested return', function (): void {
    $return = requestedReturn();

    $result = app(ReviewReturn::class)->handle($return, ReturnStatus::Denied, 'Outside policy', returnReviewer());

    expect($result->status)->toBe(ReturnStatus::Denied);
});

it('refuses an illegal transition', function (): void {
    $return = requestedReturn();
    $return->update(['status' => ReturnStatus::Refunded]);

    expect(fn () => app(ReviewReturn::class)->handle($return, ReturnStatus::Approved, null, returnReviewer()))
        ->toThrow(IllegalReturnStatusTransitionException::class);
});

it('requires update_return', function (): void {
    $return = requestedReturn();

    expect(fn () => app(ReviewReturn::class)->handle($return, ReturnStatus::Approved, null, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    expect($return->fresh()->status)->toBe(ReturnStatus::Requested);
});

it('is a no-op when already at the target, but still checks authorization', function (): void {
    $return = requestedReturn();
    $return->update(['status' => ReturnStatus::Approved]);

    // Same target: no exception, no change.
    $result = app(ReviewReturn::class)->handle($return, ReturnStatus::Approved, 'ignored', returnReviewer());
    expect($result->status)->toBe(ReturnStatus::Approved);

    // Same no-op, unauthorised actor: still denied.
    expect(fn () => app(ReviewReturn::class)->handle($return, ReturnStatus::Approved, null, User::factory()->create()))
        ->toThrow(AuthorizationException::class);
});
