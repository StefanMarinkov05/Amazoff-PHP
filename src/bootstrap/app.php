<?php

declare(strict_types=1);

use App\Http\Controllers\Payment\StripeWebhookController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\VerifyStripeWebhookSignature;
use App\Support\CookieConsent;
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

        /*
         * The cookie-consent banner writes `cookie_consent` from JavaScript
         * (ePrivacy Art. 5(3), ADR-0019), so it is a plaintext value the
         * `EncryptCookies` middleware must not try to decrypt — it would
         * discard it as tampered and the banner would reappear on every
         * page. It carries no security weight: the only values it holds are
         * `accepted` / `rejected`, and `App\Support\CookieConsent` treats
         * anything else as "not granted".
         */
        $middleware->encryptCookies(except: [
            CookieConsent::COOKIE,
        ]);

        // Global, not web-only: AdminPanelProvider builds its own
        // middleware stack and does not inherit `web`, so a web-group-only
        // middleware would leave /admin unheadered — the higher-value
        // target. Reaching the Stripe webhook route too is harmless; Stripe
        // ignores headers it does not recognise. See SetSecurityHeaders'
        // own docblock and
        // reference/testing/security-testing/sec-001-to-004.md, SEC-004.
        $middleware->append(SetSecurityHeaders::class);

        /*
         * Required by any host that terminates TLS at an edge proxy and
         * forwards the request onward over plain HTTP — Railway (ADR-0023),
         * and equally a load balancer or CDN in front of a Forge VPS.
         *
         * Without this, `$request->isSecure()` is false for every request on
         * such a host, and three things break at once, none of them loudly:
         *
         * 1. `url()`/`route()` generate `http://` links on an HTTPS site, so
         *    browsers block them as mixed content.
         * 2. `config('filesystems.disks.public.url')` is built from
         *    `APP_URL`, so product and article images resolve against the
         *    wrong scheme — the catalogue renders with broken images, which
         *    on a demo box looks like the seed failed rather than like a
         *    proxy-trust problem.
         * 3. `SESSION_SECURE_COOKIE=true` (deploy-and-host.md requires it)
         *    tells Laravel to mark the cookie `Secure`, but a framework that
         *    believes the connection is plaintext is the half of that pair
         *    most likely to surprise someone reading only the env file.
         *
         * `at: '*'` trusts whatever proxy fronts the app rather than naming
         * an address. On Railway the edge's address is neither stable nor
         * documented, so an allow-list would be a value to maintain with no
         * way to verify it; the platform is the only route to the container,
         * which is what makes the wildcard sound here rather than lazy. A
         * self-managed host with a known, fixed proxy address should name it
         * instead.
         *
         * AWS_ELB is deliberately absent from the header set: `Forwarded`
         * plus the four `X-Forwarded-*` headers is what Railway's proxy
         * actually sends, and trusting a header nothing sets is surface for
         * no benefit.
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
