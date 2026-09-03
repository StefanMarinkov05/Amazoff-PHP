<?php

declare(strict_types=1);

use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Http\Kernel;

/*
 * SEC-004 (reference/security-testing.md): an OWASP ZAP baseline scan found
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
        ->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
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
