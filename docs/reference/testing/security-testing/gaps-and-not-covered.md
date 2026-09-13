# Hardening gaps, and what is not covered

Part of the [security-testing index](../security-testing.md). See
[method-and-summary.md](method-and-summary.md) for how this pass was run.

## Hardening gaps (not vulnerabilities today)

Neither is exploitable in local dev over HTTP; both matter on first deploy.

- **No security headers.** No middleware sets them and `docker/nginx/*.conf`
  contains no `add_header` at all — so no HSTS, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, or CSP. With Livewire and
  Alpine, a CSP needs designing rather than pasting; the other four are
  one middleware. **Confirmed by a live scan, not only by reading config —
  see SEC-004** in [sec-001-to-004.md](sec-001-to-004.md) for the full ZAP
  finding and per-header fix.
- **`SESSION_SECURE_COOKIE` is unset** and absent from `.env.example`.
  `http_only` (true) and `same_site` (lax) are correct; `secure` is what
  keeps the session cookie off plaintext HTTP, and production must set it.
  See SEC-013 in [sec-011-to-013.md](sec-011-to-013.md).

## Not covered

- **Admin-panel scan coverage is partial, not complete.** SEC-007's
  authenticated run (see [sec-005-to-007.md](sec-005-to-007.md)) reached
  **44 distinct admin paths** while signed in, but its session expired
  mid-scan and 4,550 later requests were redirected to login. The remaining
  panel surface is unscanned. Fixing this means giving ZAP a context
  authentication method with a `loggedOutRegex` so it re-authenticates
  instead of silently continuing as a guest.
- **Only the administrator role was scanned.** The authenticated run used
  `admin@example.com`. `content_editor` and `warehouse_employee` were probed
  at the route level across all 19 admin resources (the role matrix in
  [what-held.md](what-held.md)) but never *crawled* by a scanner, so
  role-specific injection surface behind those two accounts is untested. The
  matrix proves which resources each role may open; it says nothing about
  the inputs on the pages they may open. sqlmap and Metasploit were
  deliberately not run — see the reasoning recorded separately: sqlmap
  fuzzes for a class of bug (string-concatenated SQL) already ruled out by
  reading every query path, and Metasploit targets known CVEs in deployed
  services, not a bespoke app's business-logic bugs, which is where this
  codebase's real findings (SEC-001, SEC-002 — see
  [sec-001-to-004.md](sec-001-to-004.md)) actually were.
- **Response-header inspection against every live page**, not only the two
  ZAP scanned — the role sweep probed authorization status codes across
  the full admin route set, not each page's headers.
- **Filament's own surface** was read at the policy layer, not probed. Its
  form/table plumbing is upstream's to secure.
- **Courier credential handling has not been reviewed.** §37 #12–15 is now
  built — `App\Contracts\CourierGateway` behind `EcontGateway`/
  `SpeedyGateway`, with `CreateShipment`/`TransitionShipmentStatus` Actions —
  since this gap was first recorded, when no courier code existed. Speedy's
  `HasSpeedyCredentials` trait embeds `services.speedy.username`/`.password`
  in every request body rather than an auth header; neither that pattern nor
  Econt's own credential handling has had a security pass run against it.

- **`/account/orders`** now has a clean, correctly-scoped authenticated full
  scan — see [what-held.md](what-held.md). The seven informational pages
  (`about`, `cookies`, `delivery`, `faq`, `payment-information`, `privacy`,
  `terms`) got a clean baseline pass — also there.

- **Browser-enforced controls, beyond the CSP finding.** SEC-009 (see
  [sec-008-to-010.md](sec-008-to-010.md)) came from asking whether the CSP
  permits what the app loads. The same question has not been asked of
  cookie attributes (`Secure`, `SameSite` under HTTPS), CORS on the
  Livewire endpoint, or `Referrer-Policy`'s effect on the Stripe return
  URL — which carries a `payment_intent_client_secret` in the query string
  and is therefore worth checking against referrer leakage specifically.
