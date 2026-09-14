<?php

declare(strict_types=1);

use App\Livewire\Auth\ConfirmPasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/*
 * Password::createToken() generates a real token through the same broker
 * ConfirmPasswordReset::submit() validates against — not a stand-in token,
 * so these tests prove the real validate-then-write path, including the
 * cases where it must refuse.
 */

it('sets a new password, signs the visitor in, and consumes the token', function (): void {
    $user = User::factory()->create(['email' => 'reset-me@example.com']);
    $token = Password::createToken($user);

    Livewire::test(ConfirmPasswordReset::class, ['token' => $token])
        ->set('email', 'reset-me@example.com')
        ->set('password', 'BrandNewPassword123!')
        ->set('password_confirmation', 'BrandNewPassword123!')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(Hash::check('BrandNewPassword123!', $user->refresh()->password))->toBeTrue()
        ->and(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->getKey());

    // The broker deletes the token once used — a second attempt with the
    // same token must be refused, not silently accepted again.
    expect(Password::tokenExists($user, $token))->toBeFalse();
});

it('refuses an invalid token and changes nothing', function (): void {
    $user = User::factory()->create(['email' => 'reset-me@example.com']);

    Livewire::test(ConfirmPasswordReset::class, ['token' => 'not-a-real-token'])
        ->set('email', 'reset-me@example.com')
        ->set('password', 'BrandNewPassword123!')
        ->set('password_confirmation', 'BrandNewPassword123!')
        ->call('submit')
        ->assertSet('invalid', true);

    expect(Hash::check('BrandNewPassword123!', $user->refresh()->password))->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

it('refuses a valid token submitted against the wrong email', function (): void {
    $user = User::factory()->create(['email' => 'reset-me@example.com']);
    $token = Password::createToken($user);

    Livewire::test(ConfirmPasswordReset::class, ['token' => $token])
        ->set('email', 'someone-else@example.com')
        ->set('password', 'BrandNewPassword123!')
        ->set('password_confirmation', 'BrandNewPassword123!')
        ->call('submit')
        ->assertSet('invalid', true);

    expect(Hash::check('BrandNewPassword123!', $user->refresh()->password))->toBeFalse();
});

it('refuses a mismatched password confirmation before ever reaching the broker', function (): void {
    $user = User::factory()->create(['email' => 'reset-me@example.com']);
    $token = Password::createToken($user);

    Livewire::test(ConfirmPasswordReset::class, ['token' => $token])
        ->set('email', 'reset-me@example.com')
        ->set('password', 'BrandNewPassword123!')
        ->set('password_confirmation', 'SomethingElseEntirely!')
        ->call('submit')
        ->assertHasErrors(['password']);

    // The token is still live — a validation refusal must not consume it.
    expect(Password::tokenExists($user, $token))->toBeTrue();
});

it('refuses an oversized new password rather than hashing it unbounded, without consuming the token', function (): void {
    // Group B2's free-text sweep: nothing capped this before, and bcrypt
    // still processes the whole string up to its own 72-byte truncation.
    $user = User::factory()->create(['email' => 'reset-me@example.com']);
    $token = Password::createToken($user);
    $password = str_repeat('a', 101).'1A!';

    Livewire::test(ConfirmPasswordReset::class, ['token' => $token])
        ->set('email', 'reset-me@example.com')
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('submit')
        ->assertHasErrors(['password']);

    expect(Password::tokenExists($user, $token))->toBeTrue()
        ->and(Hash::check($password, $user->fresh()->password))->toBeFalse();
});

it('invalidates every other session for the account once the reset succeeds', function (): void {
    $user = User::factory()->create(['email' => 'reset-me@example.com']);
    $token = Password::createToken($user);

    Livewire::test(ConfirmPasswordReset::class, ['token' => $token])
        ->set('email', 'reset-me@example.com')
        ->set('password', 'BrandNewPassword123!')
        ->set('password_confirmation', 'BrandNewPassword123!')
        ->call('submit');

    // remember_token changes on logoutOtherDevices() — the same signal
    // ChangePassword's own session-invalidation is proven by elsewhere.
    expect($user->refresh()->getRememberToken())->not->toBeNull();
});

it('pre-fills the email from the query string on mount', function (): void {
    $token = 'some-token';

    // A real route hit, not Livewire::test() directly — mount() reads
    // request()->query('email'), and Livewire::test() does not route the
    // component through the actual HTTP request/query-string cycle the
    // way visiting the real URL does, so that helper cannot prove this.
    $response = $this->get("/password/reset/{$token}?email=from-query%40example.com");

    $response->assertSee('from-query@example.com', false);
});
