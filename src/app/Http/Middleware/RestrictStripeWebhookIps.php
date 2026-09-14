<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second half of Stripe's own recommended pairing for a webhook
 * endpoint — IP allowlisting alongside signature verification
 * (`VerifyStripeWebhookSignature`, which is the layer that actually
 * authenticates the request and runs unconditionally regardless of this
 * class). This one narrows *where* a request may come from; it never
 * substitutes for proving *what* it is.
 *
 * ## Why this is application middleware, not an edge/nginx allow-list
 *
 * `docker/nginx/stripe-ip-allowlist.conf.example` is the traditional shape
 * of this control and is correct for a self-managed nginx — but this
 * deploy's edge is Railway's Railpack/Caddy build (ADR-0024), which has no
 * committed config file this repository can add an allow-list to.
 * `$request->ip()` is still trustworthy here: `bootstrap/app.php`'s
 * `trustProxies(at: '*')` resolves the real client IP out of
 * `X-Forwarded-For` before this middleware ever runs — the exact
 * resolution the nginx approach would otherwise need `real_ip_header`/
 * `set_real_ip_from` to perform, done once for the whole application
 * instead of once per edge config.
 *
 * ## Why it fails open, not closed, when unconfigured
 *
 * `VerifyStripeWebhookSignature` fails closed on a missing secret, because
 * that is the sole authentication for the route and a misconfigured
 * deployment silently accepting unsigned webhooks is the exact failure it
 * exists to prevent. This class is additive, not the endpoint's
 * authentication, and Stripe's published ranges are a moving target — the
 * failure mode `pentest-the-system.md` warns about ("a fraction of your
 * webhooks start 403ing with nothing in the application logs") is *this*
 * check going stale, not an attacker. Failing closed here would turn a
 * missed refresh into silently dropped real payments; failing open with a
 * loud log means a stale or absent list costs this one layer for that
 * request, not the payment.
 */
class RestrictStripeWebhookIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.stripe.webhook_allowed_ips');

        if (! is_string($configured) || trim($configured) === '') {
            Log::warning('Stripe webhook IP allow-list is not configured; skipping the check.');

            return $next($request);
        }

        $ranges = array_values(array_filter(array_map('trim', explode(',', $configured))));

        $ip = $request->ip();

        if (is_string($ip) && IpUtils::checkIp($ip, $ranges)) {
            return $next($request);
        }

        Log::warning('Stripe webhook rejected: source IP not in the configured allow-list.', [
            'ip' => $ip,
        ]);

        // Same opaque shape VerifyStripeWebhookSignature uses: a caller
        // probing the endpoint learns only that it failed, not which layer
        // rejected it.
        return response()->json(['error' => 'Forbidden.'], 403);
    }
}
