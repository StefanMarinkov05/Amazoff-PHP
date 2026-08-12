<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // administrator holds no permission rows; this is what grants it
        // everything. One line in one provider rather than 108 attached
        // permissions that would drift as the catalogue grows.
        //
        // Returning null rather than false when the role is absent is
        // load-bearing: false here would deny every check for every other
        // user before their policy ran. null means "no opinion", so Laravel
        // continues to spatie's own before-callback and then the policy.
        //
        // The cost is that a policy can no longer deny an administrator
        // anything. A rule like "nobody may delete a paid order" cannot live
        // in a policy once this is registered — it belongs in the Action as a
        // domain invariant, which is where ADR-0004 already puts the question
        // of whether a transition is legal at all.
        Gate::before(fn (User $user, string $ability): ?bool => $user->hasRole('administrator') ? true : null);
    }
}
