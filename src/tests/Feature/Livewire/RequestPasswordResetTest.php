<?php

declare(strict_types=1);

use App\Livewire\Auth\RequestPasswordReset;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The account-enumeration rule Login's own docblock states — one message
 * for wrong-email vs. wrong-password — applies here too: the response must
 * not tell a visitor whether an email has an account. These tests assert
 * the visible response is identical either way, and separately assert what
 * actually happened (a notification sent, or not) against the real
 * Password broker rather than a stand-in.
 */

it('sends a real reset notification for a known email', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'known@example.com']);

    Livewire::test(RequestPasswordReset::class)
        ->set('email', 'known@example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('shows the same success state for an email with no account, and sends nothing', function (): void {
    Notification::fake();

    Livewire::test(RequestPasswordReset::class)
        ->set('email', 'nobody-here@example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    Notification::assertNothingSent();
});

it('refuses a malformed email before ever reaching the broker', function (): void {
    Notification::fake();

    Livewire::test(RequestPasswordReset::class)
        ->set('email', 'not-an-email')
        ->call('submit')
        ->assertHasErrors(['email'])
        ->assertSet('sent', false);

    Notification::assertNothingSent();
});

it('throttles repeated requests from the same IP', function (): void {
    Notification::fake();

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(RequestPasswordReset::class)
            ->set('email', "attempt{$i}@example.com")
            ->call('submit');
    }

    Livewire::test(RequestPasswordReset::class)
        ->set('email', 'one-more@example.com')
        ->call('submit')
        ->assertHasErrors(['email']);
});

/*
 * Password reset is reachable while signed in — a customer who no longer
 * knows their current password recovers it from /account/password, and
 * that only works if the flow is not gated to guests. See routes/web.php.
 */
it('is reachable by a signed-in user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('password.request'))->assertOk();
    $this->actingAs($user)->get(route('password.reset', ['token' => 'anything']))->assertOk();
});

it('is linked from the change-password page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.change'))
        ->assertOk()
        ->assertSee(route('password.request'), false);
});
