<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountIsActive;
use App\Livewire\Auth\ChangePassword;
use App\Livewire\Auth\Register;
use App\Models\User;
use Illuminate\Session\Middleware\AuthenticateSession;
use Livewire\Livewire;

/*
 * Three rules that all have the same shape: a session outlives the thing it
 * authenticated against, so the check that ran at sign-in is not the check
 * that matters afterwards.
 *
 * None of these are concurrency tests. There is no contested row and no lock
 * deciding a winner — what was missing was enforcement on a later request, so
 * a plain Feature test is what proves it. The two-process harness in
 * tests/Concurrency is for state where a lock picks a winner (stock, coupons,
 * order status); using it here would prove nothing these do not.
 */

/*
 * ChangePassword calls Auth::logoutOtherDevices(), which only does anything
 * when AuthenticateSession is in the middleware stack. It was on Filament's
 * panel (AdminPanelProvider scaffolds it) and not on the storefront's `web`
 * group, so the guarantee the component's own docblock states — "every other
 * session for this user is invalidated" — was true for staff and false for
 * customers. That is the failure this test exists for: the code read
 * correctly, the static checks were green, and the behaviour was absent.
 */
it('puts AuthenticateSession on the storefront web group, not only the panel', function (): void {
    // The `web` group rather than the route: gatherMiddleware() returns the
    // group's *name*, unexpanded, so asserting against it would pass while
    // the group itself contained nothing.
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'account/password');

    expect($route->gatherMiddleware())->toContain('web')
        ->and(app('router')->getMiddlewareGroups()['web'])
        ->toContain(AuthenticateSession::class)
        ->toContain(EnsureAccountIsActive::class);
});

it('records a new password hash that the old password no longer matches', function (): void {
    $user = User::factory()->create(['password' => 'old-Password!1']);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current_password', 'old-Password!1')
        ->set('password', 'new-Password!2')
        ->set('password_confirmation', 'new-Password!2')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $stored = $user->fresh()->password;

    expect(Hash::check('new-Password!2', $stored))->toBeTrue()
        ->and(Hash::check('old-Password!1', $stored))->toBeFalse();
});

/*
 * The password field is what AuthenticateSession compares a session against,
 * so a change to it is what invalidates the others. Asserting the hash moved
 * (above) plus the middleware being present (first test) is what makes the
 * guarantee hold; this asserts the third piece — that the acting session is
 * re-issued rather than also being logged out, which would sign the user out
 * of the browser they just used to change their own password.
 */
it('keeps the acting user signed in after their own password change', function (): void {
    $user = User::factory()->create(['password' => 'old-Password!1']);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current_password', 'old-Password!1')
        ->set('password', 'new-Password!2')
        ->set('password_confirmation', 'new-Password!2')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->getKey());
});

it('refuses a password change that does not know the current password', function (): void {
    $user = User::factory()->create(['password' => 'old-Password!1']);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current_password', 'not-the-Password!9')
        ->set('password', 'new-Password!2')
        ->set('password_confirmation', 'new-Password!2')
        ->call('updatePassword')
        ->assertHasErrors('current_password');

    expect(Hash::check('old-Password!1', $user->fresh()->password))->toBeTrue();
});

/*
 * Deactivation and soft-deletion, mid-session.
 *
 * Login checks is_active as part of the credentials, but that is one instant.
 * Nothing re-checked it afterwards, so an employee deactivated at 10am kept
 * shopping until their session expired on its own. canAccessPanel() already
 * made this argument for the panel; EnsureAccountIsActive is the same rule
 * for the storefront.
 */
it('ends the session of a user deactivated while signed in', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get('/catalogue')->assertOk();

    $user->update(['is_active' => false]);

    $this->get('/catalogue')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('ends the session of a user soft-deleted while signed in', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get('/catalogue')->assertOk();

    $user->delete();

    $this->get('/catalogue')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('leaves an active user alone', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get('/catalogue')->assertOk();

    expect(auth()->check())->toBeTrue();
});

it('leaves a guest alone', function (): void {
    $this->get('/catalogue')->assertOk();
});

/*
 * Registration's unique rule and the users.email index are two halves of one
 * check. Between validating and inserting, another registration can take the
 * address — the rule passes, the index refuses, and before the catch the
 * loser got a raw 500 rather than a form error.
 *
 * The competing insert is planted on Eloquent's `creating` event, which is
 * the only seam that lands *between* $this->validate() and the INSERT. Doing
 * it any earlier — before ->call('register') — proves nothing: validate()
 * re-queries the unique rule at call time and rejects it there, so the catch
 * under test is never reached and the test passes with the catch deleted.
 * That is exactly what an earlier draft of this test did.
 *
 * Two real processes would prove MySQL enforces a unique index, which is not
 * ours. What is ours is that the violation becomes the same message the
 * ordinary duplicate produces, so the two are indistinguishable to the user.
 */
it('shows a form error rather than crashing when the email is taken mid-registration', function (): void {
    User::creating(function (User $user): void {
        // Fires once, after validation passed, before this insert lands.
        static $planted = false;

        if ($planted || $user->email !== 'taken@example.test') {
            return;
        }

        $planted = true;

        User::withoutEvents(fn () => User::factory()->create(['email' => 'taken@example.test']));
    });

    Livewire::test(Register::class)
        ->set('first_name', 'Ada')
        ->set('last_name', 'Lovelace')
        ->set('email', 'taken@example.test')
        ->set('password', 'a-Strong-Password!1')
        ->set('password_confirmation', 'a-Strong-Password!1')
        ->call('register')
        ->assertHasErrors('email');

    // The loser is not signed in, and the winner's row is the only one.
    expect(auth()->check())->toBeFalse()
        ->and(User::withTrashed()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('rejects an already-registered email through the unique rule in the ordinary case', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::test(Register::class)
        ->set('first_name', 'Ada')
        ->set('last_name', 'Lovelace')
        ->set('email', 'taken@example.test')
        ->set('password', 'a-Strong-Password!1')
        ->set('password_confirmation', 'a-Strong-Password!1')
        ->call('register')
        ->assertHasErrors('email');

    expect(User::withTrashed()->where('email', 'taken@example.test')->count())->toBe(1);
});
