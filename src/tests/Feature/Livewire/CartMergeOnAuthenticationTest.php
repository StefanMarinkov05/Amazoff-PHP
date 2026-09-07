<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\Cart;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * `MergeGuestCart` has been built and tested since the cart slice — including
 * two concurrency tests — and had no caller. These pin the caller.
 *
 * The case worth the most here is the *ordering*: both Login and Register
 * regenerate the session immediately after authenticating (fixation defence,
 * and non-negotiable), and a guest cart is keyed on `session_id`. Capturing
 * the cart after regeneration finds nothing and silently loses the basket —
 * which is what happened live on 2026-09-04 before this existed. The
 * "carries a guest basket through sign-in" test goes red if the capture is
 * moved after `session()->regenerate()`.
 */

function guestCartWith(int $quantity = 1, ?ProductVariation $variation = null): Cart
{
    $variation ??= ProductVariation::factory()->create();

    /** @var Cart $cart */
    $cart = Cart::create(['session_id' => Session::getId(), 'user_id' => null]);

    $cart->cartItems()->create([
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return $cart;
}

it('carries a guest basket through sign-in', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);

    $guestCart = guestCartWith(quantity: 2);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    /** @var Cart $userCart */
    $userCart = Cart::query()->where('user_id', $user->getKey())->first();

    expect($userCart)->not->toBeNull()
        ->and((int) $userCart->cartItems()->sum('quantity'))->toBe(2)
        ->and(Cart::query()->whereKey($guestCart->getKey())->exists())->toBeFalse();
});

it('sums quantities when both carts hold the same variation', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);
    /** @var ProductVariation $variation */
    $variation = ProductVariation::factory()->create();

    // The account already has a basket from a previous visit.
    /** @var Cart $userCart */
    $userCart = Cart::create(['user_id' => $user->getKey()]);
    $userCart->cartItems()->create([
        'product_variation_id' => $variation->getKey(),
        'quantity' => 3,
    ]);

    guestCartWith(quantity: 2, variation: $variation);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect((int) $userCart->fresh()->cartItems()->sum('quantity'))->toBe(5)
        ->and($userCart->fresh()->cartItems()->count())->toBe(1);
});

it('keeps both lines when the carts hold different variations', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);

    /** @var Cart $userCart */
    $userCart = Cart::create(['user_id' => $user->getKey()]);
    $userCart->cartItems()->create([
        'product_variation_id' => ProductVariation::factory()->create()->getKey(),
        'quantity' => 1,
    ]);

    guestCartWith(quantity: 1);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect($userCart->fresh()->cartItems()->count())->toBe(2);
});

it('carries a guest basket through registration', function (): void {
    $guestCart = guestCartWith(quantity: 4);

    Livewire::test(Register::class)
        ->set('first_name', 'Newly')
        ->set('last_name', 'Registered')
        ->set('email', 'newly@example.com')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->call('register')
        ->assertHasNoErrors();

    /** @var User $user */
    $user = User::query()->where('email', 'newly@example.com')->firstOrFail();

    /** @var Cart $userCart */
    $userCart = Cart::query()->where('user_id', $user->getKey())->first();

    expect($userCart)->not->toBeNull()
        ->and((int) $userCart->cartItems()->sum('quantity'))->toBe(4)
        ->and(Cart::query()->whereKey($guestCart->getKey())->exists())->toBeFalse();
});

/*
 * The merged cart now belongs to a user, so its guest expiry must be cleared
 * — otherwise `carts:expire` deletes a registered customer's basket a day
 * later. `MergeGuestCart` calls `TouchCartExpiry` for exactly this; the test
 * is here because the consequence only appears through this call path.
 */
it('clears the guest expiry from the surviving cart', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);

    /** @var Cart $userCart */
    $userCart = Cart::create([
        'user_id' => $user->getKey(),
        'expires_at' => now()->addHours(2),
    ]);

    guestCartWith(quantity: 1);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect($userCart->fresh()->expires_at)->toBeNull();
});

it('signs in normally when the visitor has no guest cart', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($user->getKey());
});

/*
 * A failed merge must never fail the login: someone who has proved their
 * identity gets signed in even if their basket cannot be folded in. Deleting
 * the captured cart between capture and apply is the reachable version of
 * this — a second tab finishing its own merge first.
 */
it('still signs the user in when the captured cart has since been deleted', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);

    $guestCart = guestCartWith(quantity: 1);
    $guestCart->cartItems()->delete();
    $guestCart->delete();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($user->getKey());
});

it('does not merge a cart belonging to a different session', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);

    /** @var Cart $strangersCart */
    $strangersCart = Cart::create(['session_id' => 'someone-elses-session', 'user_id' => null]);
    $strangersCart->cartItems()->create([
        'product_variation_id' => ProductVariation::factory()->create()->getKey(),
        'quantity' => 9,
    ]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect(Cart::query()->whereKey($strangersCart->getKey())->exists())->toBeTrue()
        ->and(Cart::query()->where('user_id', $user->getKey())->first())->toBeNull();
});
