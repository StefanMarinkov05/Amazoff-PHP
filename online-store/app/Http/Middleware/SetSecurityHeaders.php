<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the response headers `docker/nginx/*.conf` does not, and that Forge's
 * production nginx cannot be assumed to add either — see
 * `reference/security-testing.md`, SEC-004, an OWASP ZAP baseline scan that
 * found none of these present.
 *
 * Global (`bootstrap/app.php`'s `$middleware->append()`), not scoped to the
 * `web` group: the Filament panel builds its own middleware stack in
 * `AdminPanelProvider` and does not inherit `web`, so a `web`-group-only
 * middleware would leave `/admin` — the higher-value target — unheadered.
 *
 * No Content-Security-Policy here. A CSP wide enough to allow Livewire's
 * inline `wire:` attribute handlers and Alpine's `x-data` without `unsafe-*`
 * defeating its own purpose needs deliberate design, not a pasted default —
 * SEC-004 records it as a separate, scoped-out piece of work.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // DENY, not SAMEORIGIN: nothing in this application legitimately
        // frames its own pages, so there is no origin to allow.
        $response->headers->set('X-Frame-Options', 'DENY');

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Deny every browser feature this application does not use. Add an
        // entry here only when a feature is actually wired up, never
        // pre-emptively.
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), camera=(), microphone=(), payment=()'
        );

        return $response;
    }
}
