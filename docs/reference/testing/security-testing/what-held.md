# What held

Part of the [security-testing index](../security-testing.md). See
[method-and-summary.md](method-and-summary.md) for how this pass was run.

---

### Role-based access control — verified live at the route level

§37 #18 ("user roles cannot access prohibited features") was tested by
logging in as each account and requesting every admin resource **by URL** —
a hidden nav link is not access control, so the route is what was probed, not
the menu. HTTP status per role, 2026-09-03:

| Route | editor | warehouse | customer (no role) |
|---|---|---|---|
| `/admin` (dashboard) | 200 | 200 | **403** |
| `/admin/articles` | 200 | 403 | 403 |
| `/admin/orders` | 403 | 200 | 403 |
| `/admin/inventories` | 403 | 200 | 403 |
| `/admin/shipments` | 403 | 200 | 403 |
| `/admin/carriers` | 403 | 200 | 403 |
| `/admin/products` | 403 | 403 | 403 |
| `/admin/payments` | **403** | **403** | 403 |
| `/admin/users` | **403** | **403** | 403 |
| `/admin/roles` | **403** | **403** | 403 |
| `/admin/coupons` | 403 | 403 | 403 |
| `/admin/product-reviews` | 403 | 403 | 403 |
| `/admin/contact-messages` | 403 | 403 | 403 |

Re-run and extended on 2026-09-04 to the resources the first sweep did not
reach, driven through the real HTTP kernel rather than by hand. Same result
shape, no change to any row above:

| Route | editor | warehouse | customer (no role) |
|---|---|---|---|
| `/admin/tags` | 200 | 403 | 403 |
| `/admin/article-categories` | 200 | 403 | 403 |
| `/admin/product-categories` | 403 | 403 | 403 |
| `/admin/attributes` | 403 | 403 | 403 |
| `/admin/attribute-values` | 403 | 403 | 403 |
| `/admin/brands` | 403 | 403 | 403 |
| `/admin/newsletter-subscribers` | 403 | 403 | 403 |

That completes the sweep at 19 of 19 admin resources against all four
accounts. The two 200s are correct and were the reason for extending: the
content editor holds `viewAny_tag` and `viewAny_article_category`, so a 403
there would have been a *missing* grant rather than a leak — the sweep
confirms the role reaches exactly the three resources §3.3 gives it
(`articles`, `article-categories`, `tags`) and no fourth.

Every §3.3 prohibition held: the **content editor** reached only the
dashboard and articles, and was denied payment, user, role, and carrier data
by a real 403, not a hidden button. The **warehouse employee** reached
orders, inventory, shipments, and carriers (which it needs to create
shipments) and nothing else. A plain **customer** was refused the panel
outright — `/admin` itself returned 403, so `canAccessPanel()`'s
`STAFF_ROLES` allow-list is the gate, exactly as intended. §37 #18 passes.

### Other checks

Checked and correct, recorded so a later pass does not re-derive it.

| Area | Result |
|---|---|
| SQL injection | None reachable. Every `LIKE` binds parameters; the two `DB::raw` calls in Actions cast `(int)` first; all widget `selectRaw` is static SQL |
| Sort-parameter injection | `ProductList::safeSortBy()` is an `array_key_exists` allow-list against a fixed const with a safe fallback; `safeSortDir()` is binary. `ORDER BY` cannot be bound, so this is the path that usually fails — it does not here |
| XSS | Zero `{!! !!}` in the entire view layer. `Article::safeContent()` sanitises and returns `HtmlString`, so `{{ }}` renders rich text without a raw echo existing anywhere (ADR-0015) |
| Mass assignment | Every model uses `$fillable`, never `$guarded`. Roles live in a pivot (`spatie/laravel-permission`) and are structurally unreachable by `User::create()` |
| Privilege escalation via registration | `Register` assigns field-by-field from validated data, hardcodes `is_active => true`, assigns no role |
| Session fixation | `session()->regenerate()` after both login and registration, unconditionally |
| Account enumeration | One message for wrong-email and wrong-password; `is_active` folded into the credentials so a deactivated account fails identically |
| Brute force | `RateLimiter`, 5/min, keyed on email **and** IP so one attacker cannot lock out a real customer |
| Password change | Requires `current_password`; `logoutOtherDevices()` backed by `AuthenticateSession` in the `web` group — without that middleware the call silently does nothing |
| Stale sessions | `EnsureAccountIsActive` on `web`, plus `canAccessPanel()` re-checking `is_active` per request, so deactivation ends access on the next request rather than the next login |
| CSRF | Laravel default everywhere; the Stripe webhook is registered outside the `web` group entirely, so its exemption is structural rather than an opt-out someone can re-add |
| Webhook signature | Fails closed on a missing secret (500 + `Log::critical`); raw body, never `$request->all()`; tolerance floored at 60s to defeat stripe-php's `if ($tolerance > 0)` skip; accepts multiple secrets for rotation |
| Webhook replay | Signature freshness plus independent idempotency on `payment_events.stripe_event_id` — two defences, since a signature alone does not make in-window replay harmless |
| IDOR on confirmation | `OrderConfirmation` scopes to owner or session claim, re-checks on every render, 404s rather than 403s |
| Authorization model | `Gate::before` grants `administrator`; every other policy is convention-discovered. `Role` is explicitly bound because its package namespace defeats convention and would otherwise fail **open** |
| Editor deny-list | `content_editor` is denied payment, carrier, user, role and setting by name, traced to §3.3 |
| Logout | POST-only, so no `<img src>` can trigger it |
| Dependencies (JS) | `npm audit --omit=dev`: 0 vulnerabilities |

**`/orders/track`, full active ZAP scan, 2026-09-06** (baseline passive
scan the same day already covered the header/config class — see the
`/orders/track` entry that used to sit here, now folded into this row
since both scans are complete). The active scan ran under a `-m 12g
--memory-swap 12g` Docker cap, took ~1h33m (18:41–20:14), and completed
cleanly (`docker inspect`: `OOMKilled: false`, exit code 2 — ZAP's own
"warnings found" code, not a crash). All 136 active-scan rules passed with
**0 alerts**, including every injection-class rule that matters here:
generic and DB-specific timing SQLi (MySQL/MsSQL/Oracle/PostgreSQL/
Hypersonic), reflected/persistent/DOM-based XSS, path traversal, remote
file inclusion, SSTI, XXE, command injection, NoSQL injection. 5 WARN-NEW
findings, all already-triaged header/config categories overlapping the
same-day baseline's 7 (`Cookie No HttpOnly Flag`, `Cross-Domain JavaScript
Source File Inclusion`, `CSP: script-src unsafe-inline`, `Sub Resource
Integrity Attribute Missing`, `Cross-Origin-Resource-Policy Header
Missing`) — dev-server artifacts and informational-only items, nothing
new. Report: `scratchpad/zap-orders-track-full5.html`/`.xml` — **not
committed** (nothing in `scratchpad/` has been `git add`ed), but not
covered by a `.gitignore` rule either; don't assume it's protected from an
accidental `git add -A`.

This closes the injection-class gap the earlier baseline-only pass left
open. It also resolves a standing open question from four earlier failed
attempts (`how-to/troubleshooting/payments-and-security-tooling.md`'s ZAP
entry has the full account):
those were OOM-killed at `DomXssScanRule`'s headless-browser launch at both
a 6 GB and a 10 GB Docker memory cap, reproducibly. A 12 GB cap was
sufficient — the fifth attempt shows no browser-launch failure message at
all in its log, and finishes markedly slower (1h33m) than SEC-005's 48-min
`/catalogue` run (see [sec-005-to-007.md](sec-005-to-007.md)) despite a
much smaller attack surface, consistent with the extra headroom being
needed and used during that rule specifically.
`how-to/set-up-security-and-quality-tools.md`'s memory-cap guidance is
updated to 12 GB accordingly.

**The seven informational pages, baseline (passive) scan, 2026-09-06** —
`about`, `cookies`, `delivery`, `faq`, `payment-information`, `privacy`,
`terms` (every file in `resources/views/pages/`). Full active scans were
deliberately not run here: these are static prose pages with no form
fields or user input, so there is nothing for the injection-class rules a
full scan exists to test to act on — a passive pass is not a shortcut here,
it is the appropriately-scoped tool. Each page scanned independently
(`zap-baseline.py` against that one URL, not a spider crawl from one
seed), all seven returning **the identical result**: 0 FAIL, 60 PASS, 7
WARN-NEW, all already-triaged site-wide categories from the SEC-004/006/009
passes and the `/orders/track` baseline above — `Cookie No HttpOnly Flag`,
`Cross-Domain JavaScript Source File Inclusion`, `Non-Storable Content`,
`CSP: script-src unsafe-eval`, `Session Management Response Identified`,
`Sub Resource Integrity Attribute Missing`, `Cross-Origin-Embedder-Policy
Header Missing`. No page-specific finding on any of the seven. Reports:
`scratchpad/zap-baseline-<page>.html`/`.xml` for each — **not committed**,
not covered by `.gitignore` either.

`/account/orders` is not included here — it requires authentication and
redirects anonymously, so a real pass needs the existing `zap-auth.yaml`
authenticated-scan setup (`how-to/pentest-the-system.md`'s "The
authenticated scan" section), not a bare unauthenticated `docker run`. That
remains open — see [gaps-and-not-covered.md](gaps-and-not-covered.md).
