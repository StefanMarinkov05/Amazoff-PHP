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
 * The Content-Security-Policy here is deliberately **not** the strict one a
 * generator would emit, and the reason is measured rather than assumed: this
 * stack cannot run a strict CSP without breaking. Alpine evaluates its
 * attribute expressions (`x-data="{ open: false }"`, `x-on:click="..."`) at
 * runtime through the equivalent of `eval`, which needs `unsafe-eval`; the
 * rendered catalogue carries inline `<script>` and `<style>` blocks and 176
 * inline `style="..."` attributes, which need `unsafe-inline`. A policy
 * forbidding those does not harden the app, it stops it working.
 *
 * So the policy buys what it *can* buy, which is not nothing:
 * `frame-ancestors 'none'` (clickjacking, and unlike `X-Frame-Options` it is
 * the standard modern browsers actually honour), `object-src 'none'`
 * (Flash/plugin embedding), `base-uri 'self'` (stops an injected `<base>`
 * rewriting every relative URL on the page), and `form-action 'self'`
 * (stops an injected form posting credentials off-site). Those four are the
 * directives that hold even when `script-src` has to be permissive.
 *
 * Tightening this needs frontend work first — CSP hashes or nonces per inline
 * block, and Alpine's CSP build — not a header change. `SEC-005` records the
 * measurement.
 */
class SetSecurityHeaders
{
    /**
     * Sources permitted for scripts and styles.
     *
     * `unsafe-eval` and `unsafe-inline` are required by Alpine and Livewire —
     * see the class docblock. Vite's dev server is allowed only outside
     * production, where it serves the un-bundled assets from its own origin;
     * a production build emits same-origin bundles and must not permit it.
     */
    private function scriptAndStyleSources(): string
    {
        return app()->isProduction()
            ? "'self' 'unsafe-inline' 'unsafe-eval'"
            : "'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173";
    }

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

        $sources = $this->scriptAndStyleSources();

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src {$sources}",
            "style-src {$sources}",
            // No third-party font host — Instrument Sans is bundled through
            // Vite rather than fetched from Google or Bunny. It still needs
            // the dev-server origin outside production, because Vite serves
            // the .woff2 files from :5173 until they are built; those
            // requests come from CSS, so they do not appear in the HTML.
            // Verified in a browser: with 'self' alone, 12 font loads are
            // blocked and the page falls back to a system face.
            app()->isProduction()
                ? "font-src 'self' data:"
                : "font-src 'self' data: http://localhost:5173",
            // data: and blob: because product images and any generated
            // preview are served that way; https: so an admin-pasted remote
            // image renders rather than silently breaking the panel.
            "img-src 'self' data: blob: https:",
            // Livewire polls its own origin; Vite's dev server uses a
            // websocket for hot reload, which connect-src governs too.
            app()->isProduction()
                ? "connect-src 'self'"
                : "connect-src 'self' http://localhost:5173 ws://localhost:5173",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        // same-site, not same-origin: the Vite dev server is a different
        // origin on the same site in local development, and blocking it
        // would break asset loading there for no production gain.
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');

        return $response;
    }
}
