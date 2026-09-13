<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the response headers `docker/nginx/*.conf` does not, and that Forge's
 * production nginx cannot be assumed to add either — see
 * `reference/testing/security-testing/sec-001-to-004.md`, SEC-004, an OWASP
 * ZAP baseline scan that found none of these present.
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
     * Stripe's own origins, by the job each one does.
     *
     * Named constants rather than literals scattered through the policy,
     * because the four directives below have to agree: adding a Stripe
     * origin to `script-src` and forgetting `connect-src` produces a
     * payment form that renders and cannot talk to Stripe, which is a worse
     * failure than blocking it outright — it looks like it works.
     *
     * SEC-009: the first version of this policy named none of them, so
     * `js.stripe.com` was blocked and card payment could not complete in any
     * environment. Stripe documents these as the required set.
     */
    private const STRIPE_SCRIPT = 'https://js.stripe.com';

    private const STRIPE_API = 'https://api.stripe.com';

    /**
     * `js.stripe.com` frames the Payment Element's own card fields (PCI:
     * the card number must be served by Stripe, never by us);
     * `hooks.stripe.com` serves the 3-D Secure challenge. Both are frames
     * *we* embed, which is `frame-src` — unrelated to `frame-ancestors`,
     * which governs who may embed **us** and stays `'none'`.
     */
    private const STRIPE_FRAMES = 'https://js.stripe.com https://hooks.stripe.com';

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

        // Keyed on $request->isSecure(), not app()->isProduction(): this
        // deploy runs APP_ENV=demo (ADR-0023), and the header's own hazard
        // is orthogonal to that flag anyway — HSTS instructs the browser to
        // refuse plain HTTP to this host for the next year, so sending it
        // over a connection that only *looks* secure (a broken proxy trust
        // setup, isSecure() wrongly true) locks a visitor out until the max-age
        // expires. isSecure() already reflects Railway's TLS-terminating
        // proxy correctly once trustProxies(at: '*') resolves it
        // (bootstrap/app.php), so gating on it rather than the environment
        // means the header appears exactly when the connection it protects
        // is real, on any host, and never over the plain-HTTP connection
        // local Docker Compose actually serves.
        //
        // No preload: submission to the browser preload list is a one-way,
        // slow-to-reverse commitment this repo has not made, and Railway's
        // own *.up.railway.app domain is shared with every other Railpack
        // deploy on the platform — preloading it is not this app's call to
        // make. includeSubDomains is safe without preload because it only
        // affects this exact host's own subdomains.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Deny every browser feature this application does not use. Add an
        // entry here only when a feature is actually wired up, never
        // pre-emptively.
        //
        // `payment` is the one exception, and it is wired up: the Payment
        // Element renders Apple Pay / Google Pay through the Payment Request
        // API, which `payment=()` disables outright. Delegated to Stripe's
        // own frame rather than opened to `*` — the allow-list form is what
        // keeps this a policy rather than a hole.
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), camera=(), microphone=(), payment=(self "'.self::STRIPE_SCRIPT.'")'
        );

        $sources = $this->scriptAndStyleSources();

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            // Stripe.js is loaded from Stripe's own domain deliberately (PCI
            // guidance: card fields must be served by Stripe, not by us), so
            // the policy has to permit the one origin that serves it.
            'script-src '.$sources.' '.self::STRIPE_SCRIPT,
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
            // No `https:` wildcard — see SEC-006/SEC-007's reasoning: it
            // would let an injected <img> beacon to any host on the
            // internet, and ZAP's CSP rule flags exactly that as Medium.
            // One named exception, not a wildcard: Filament's default
            // avatar provider (UiAvatarsProvider, unconfigured — nothing in
            // this project chose it) fetches a generated placeholder from
            // ui-avatars.com for any staff account with no uploaded avatar.
            // Missed when this policy was first measured, because that
            // pass checked the storefront and the panel's own asset paths,
            // not every third-party call Filament itself makes by default —
            // found via the admin panel blocking the request outright.
            'img-src \'self\' data: blob: https://ui-avatars.com',
            // Livewire polls its own origin; Vite's dev server uses a
            // websocket for hot reload, which connect-src governs too.
            // Stripe.js calls api.stripe.com directly from the browser to
            // tokenise the card and confirm the intent. Without it the
            // Payment Element renders and silently cannot submit.
            app()->isProduction()
                ? 'connect-src \'self\' '.self::STRIPE_API
                : 'connect-src \'self\' http://localhost:5173 ws://localhost:5173 '.self::STRIPE_API,
            // The card fields and the 3-D Secure challenge are Stripe-served
            // iframes this page embeds. Without this directive `default-src
            // 'self'` blocks both, and 3DS fails at the moment a bank asks
            // the customer to authenticate.
            'frame-src '.self::STRIPE_FRAMES,
            // Unchanged, and unrelated to frame-src above: this governs who
            // may embed *us*, and the answer is still nobody.
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
