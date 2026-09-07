<?php

declare(strict_types=1);

use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Http\Kernel;

/*
 * SEC-004 (reference/testing/security-testing/sec-001-to-004.md): an OWASP ZAP baseline scan found
 * no security headers on any response. SetSecurityHeaders closes it, applied
 * globally rather than to the `web` group specifically — AdminPanelProvider
 * builds its own middleware stack and does not inherit `web`, so a
 * web-group-only middleware would leave /admin, the higher-value target,
 * unheadered. Both cases are asserted here for exactly that reason: a
 * storefront-only test would not have caught the panel gap.
 */

it('sets the hardening headers on a storefront response', function (): void {
    $this->get('/catalogue')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        // `payment` is delegated to Stripe's frame rather than denied
        // outright: the Payment Element renders Apple Pay / Google Pay
        // through the Payment Request API, which `payment=()` disables.
        // SEC-009.
        ->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=(self "https://js.stripe.com")')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
});

it('sets a CSP carrying the four directives that hold despite a permissive script-src', function (): void {
    // This stack cannot run a strict CSP: Alpine evaluates its attribute
    // expressions at runtime (needs unsafe-eval) and the pages carry inline
    // style attributes (needs unsafe-inline) — see SetSecurityHeaders'
    // docblock and SEC-005. What the policy can still enforce is these four,
    // so they are what this test pins. Deleting any one turns it red.
    $csp = $this->get('/catalogue')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("frame-ancestors 'none'")   // clickjacking
        ->toContain("object-src 'none'")        // plugin embedding
        ->toContain("base-uri 'self'")          // injected <base> rewriting URLs
        ->toContain("form-action 'self'");      // injected form posting off-site
});

/*
 * SEC-009: the first version of this policy named no Stripe origin at all,
 * so `js.stripe.com` was blocked and card checkout could not complete in any
 * environment — the production branch being stricter, not looser. Nothing
 * caught it because every payment test fakes `StripeClient` server-side
 * while the CSP is a header the *browser* enforces; neither half can observe
 * the other, and the test above pins only the four always-on directives.
 *
 * These three assertions are that missing half. Each names one directive the
 * payment step genuinely needs, and each fails if its origin is dropped.
 */
it('permits the Stripe origins the payment step actually loads', function (): void {
    $csp = $this->get('/catalogue')->headers->get('Content-Security-Policy');

    // Parsed per directive, not searched as one string: asserting
    // `toContain('script-src')` and `toContain('https://js.stripe.com')`
    // separately passes even when script-src has *lost* the origin, because
    // frame-src still mentions it elsewhere in the header. Caught by
    // emptying the constant and watching this test stay green — the
    // assertion has to name which directive carries which origin.
    $directives = collect(explode(';', (string) $csp))
        ->map(fn (string $d): string => trim($d))
        ->mapWithKeys(function (string $d): array {
            [$name, $value] = array_pad(explode(' ', $d, 2), 2, '');

            return [$name => $value];
        });

    expect($directives->get('script-src'))
        // Stripe.js itself — without this the Payment Element never loads.
        ->toContain('https://js.stripe.com')
        // Stripe.js calls this directly from the browser to confirm the
        // intent; without it the form renders and silently cannot submit.
        ->and($directives->get('connect-src'))->toContain('https://api.stripe.com')
        // The card fields and the 3-D Secure challenge are Stripe-served
        // frames this page embeds. Without frame-src, default-src 'self'
        // blocks both and 3DS fails at the bank-authentication step.
        ->and($directives->get('frame-src'))
        ->toContain('https://js.stripe.com')
        ->toContain('https://hooks.stripe.com');
});

it('still refuses to let anyone else frame us', function (): void {
    // frame-src (who we may embed) and frame-ancestors (who may embed us)
    // are unrelated directives, and widening the first must not touch the
    // second. Worth its own case because "we added Stripe to the frame
    // policy" is exactly the change that could loosen the wrong one.
    $csp = $this->get('/catalogue')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'");
});

it('sets the hardening headers on the admin panel, which does not inherit the web middleware group', function (): void {
    // Unauthenticated: Filament redirects to its login page, but the global
    // middleware stack runs before that redirect, so the headers must still
    // be present. Proves the headers reach a stack the web group never
    // touches, not merely that the storefront happens to be covered.
    $this->get('/admin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('registers SetSecurityHeaders in the global middleware stack', function (): void {
    /** @var Kernel $kernel */
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);

    expect($kernel->hasMiddleware(SetSecurityHeaders::class))->toBeTrue();
});
