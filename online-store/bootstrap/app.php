<?php

declare(strict_types=1);

use App\Http\Controllers\Payment\StripeWebhookController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\VerifyStripeWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * Stripe's webhook, registered here rather than in web.php so it
         * carries *no* middleware group at all — no session, no cookies, no
         * CSRF token, no EnsureAccountIsActive. Stripe is not a browser and
         * holds none of those; giving the endpoint a session would be
         * surface with nothing behind it.
         *
         * Being outside the web group is also what makes CSRF exemption
         * automatic rather than a per-route opt-out someone could later
         * re-add by accident. CLAUDE.md pairs that exemption with signature
         * verification — "one without the other is a free-products
         * vulnerability" — so the one middleware it does carry is the one
         * that authenticates it.
         */
        then: function (): void {
            Route::middleware(VerifyStripeWebhookSignature::class)
                ->post('/stripe/webhook', StripeWebhookController::class)
                ->name('stripe.webhook');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * AuthenticateSession is what makes ChangePassword's
         * Auth::logoutOtherDevices() actually do something. Without it that
         * call rewrites the password hash and nothing checks any other
         * session against it, so every other browser stays signed in — which
         * is the opposite of what requiring current_password is for.
         *
         * Filament's panel already had this (AdminPanelProvider's own
         * ->middleware() stack, which Filament scaffolds); the storefront's
         * `web` group did not, so the protection existed for staff and not
         * for customers.
         *
         * EnsureAccountIsActive is the same class of problem one layer over:
         * a session outlives the row it authenticated against, so
         * deactivating or deleting a customer has to end their access on the
         * next request rather than at a next login they will never make.
         * canAccessPanel() already states that reasoning for the panel.
         */
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
        ]);

        // Global, not web-only: AdminPanelProvider builds its own
        // middleware stack and does not inherit `web`, so a web-group-only
        // middleware would leave /admin unheadered — the higher-value
        // target. Reaching the Stripe webhook route too is harmless; Stripe
        // ignores headers it does not recognise. See SetSecurityHeaders'
        // own docblock and
        // reference/testing/security-testing/sec-001-to-004.md, SEC-004.
        $middleware->append(SetSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
