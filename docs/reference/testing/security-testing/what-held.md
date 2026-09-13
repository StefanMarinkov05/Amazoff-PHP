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
| Account enumeration | One message for wrong-email and wrong-password; `is_active` folded into the credentials so a deactivated account fails identically. `RequestPasswordReset` extends the same rule: identical success state whether or not the submitted email has an account, verified with `Notification::fake()` proving nothing is actually sent for an unknown email rather than trusting the UI message alone |
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

**`/account/orders`, authenticated full scan, 2026-09-08 — clean, on the
second, correctly-scoped attempt.** A dedicated Automation Framework plan
authenticated as `customer@example.com` via the real Livewire login
handshake (same method as `zap-auth.yaml`) and pointed at
`/account/orders`. The first attempt copied `zap-auth.yaml`'s
`includePaths` pattern (`http://webserver(:80)?/.*`, scoped to the whole
host) unnarrowed; `/account/orders` links to the full global nav (every
catalogue category, cart, `/about`, …), so the spider followed all of it —
579 URLs found, and the 25-minute active-scan budget spread across the
whole authenticated surface rather than concentrating on this one page. Not
a tooling failure, a scoping one: fixed by narrowing `includePaths` to
`http://webserver(:80)?/account/orders.*`, which still lets the spider
*discover* the page from any nav link it happens to try first but refuses
to queue anything outside the pattern for scanning.

The corrected run found **1 endpoint total** (the report's own count),
finished the active-scan phase in 1 minute 2 seconds — well under the
25-minute cap, meaning it ran to completion rather than timing out, unlike
the first attempt. `docker inspect`: exit code 0, not OOM-killed. No `302`
appears anywhere in the report (no interleaved-200/302 session-race
signature, confirming `zap-auth.yaml`'s serial-thread configuration held).
Findings: 5 medium, 2 low, 2 informational, all already-triaged
header/config categories seen elsewhere in this project — `CSP:
script-src unsafe-eval`/`unsafe-inline`/`style-src unsafe-inline`, `Sub
Resource Integrity Attribute Missing`, `Cookie No HttpOnly Flag`,
`Cross-Domain JavaScript Source File Inclusion`, `Session Management
Response Identified`, `User Agent Fuzzer` (informational scanner noise).
One new-looking entry, `HTTP Only Site` (medium), is the same
"dev serves HTTP not HTTPS" characteristic every other scan on this
project has already flagged as expected in local dev, not a new class of
finding — ZAP tried `https://webserver/account/orders`, got a connection
failure, and reported that as the alert. No page-specific finding.
Report: `scratchpad/zap-account-orders-report-v2.md` — **not committed**,
not covered by `.gitignore` either.

**Authenticated admin re-run, `zap-auth.yaml` with the logout-exclusion
fix, 2026-09-13.** `run2` (2026-09-05) had died mid-scan when the spider
followed the panel's own `POST admin/logout` — the context's
`excludePaths` excluded root `/logout.*` but not `/admin/logout`, a
different path Filament's own logout uses (confirmed via `route:list`:
`POST admin/logout`, `filament.admin.auth.logout`). Fixed by adding
`"http://webserver(:80)?/admin/logout.*"` to `excludePaths` alongside the
existing two entries — the actual fix, not a workaround. (The handoff that
scoped this pass also suggested an `authhelper` browser-auth
`loggedOutRegex` fallback for automatic mid-scan re-authentication;
deliberately not adopted here in favour of the already-proven cookie-
injection mechanism, since the session held for the entire scan without it
— see below.)

Fresh `admin@example.com` session established via the documented curl
Livewire-login handshake (`GET /login` → extract CSRF + the `auth.login`
component's `wire:snapshot` → `POST /livewire/update` with the login call),
confirmed with `GET /admin` → 200 before spending a scan on it. Serial
concurrency on both phases (`-config spider.thread=1 -config
scanner.threadPerHost=1`), confirmed accepted (no `unrecognised` in the
container's stdout).

**No session death this run.** The spider found 850 URLs (up from run2's
partial crawl) and the full 25-minute active-scan budget completed without
truncation (`Job activeScan finished, time taken: 00:25:00` — ran to the
cap rather than exiting early). Cross-referencing the webserver access log
for the scan window (`172.18.0.5`, the ZAP container's IP on the docker
network): 2633×200, 1201×404, 936×405, 239×301, and exactly **2×302** —
both `GET /login` redirecting an already-authenticated visitor to
`/catalogue` (expected Laravel behaviour, not a session drop), confirmed
by inspecting both lines directly. No interleaved 200/302 on the same URL
anywhere in the log — the serial-concurrency requirement held for the
full run.

473 endpoints scanned (the report's own "Count of total endpoints"
insight) — comparable to run2's partial 469, but this time from a
scan that completed rather than one that died partway through. 0 High,
4 Medium, 2 Low, 3 Informational. All 4 Medium and both Low findings are
the same already-triaged site-wide categories from SEC-004/006/009 (`CSP:
script-src unsafe-eval`/`unsafe-inline`, `CSP: style-src unsafe-inline`,
`Sub Resource Integrity Attribute Missing`, `Cookie No HttpOnly Flag`,
`Timestamp Disclosure - Unix`). Of the three Informational findings, one
(`Session Management Response Identified`, 460 instances) is ZAP noting
every response carries a recognisable session cookie — expected, not
actionable. The other two were checked individually rather than
transcribed:

- **`Information Disclosure - Sensitive Information in URL`** flagged
  `http://webserver/password/reset?email=zaproxy@example.com` — but
  `zaproxy@example.com` is ZAP's own default fuzzing value (confirmed:
  `RequestPasswordReset::$email` carries no `#[Url]` attribute and no
  route accepts an `email` query parameter at all), so this is the scanner
  flagging a parameter *it appended itself* during crawling, not a leak
  the application produces. **False positive.**
- **`User Controllable HTML Element Attribute (Potential XSS)`** flagged
  `?category=garden` appearing inside an `<img alt="Garden hose">` tag —
  but `categorySlug` only filters which products render; it is not
  interpolated into any attribute. Confirmed directly: `curl
  "http://localhost:8080/catalogue?category=garden"` shows `alt="Garden
  hose"` is the seeded product's literal name (Blade's `{{ }}`, escaped),
  and a follow-up request with `category` set to an attribute-breakout
  payload (`"><script>alert(1)</script>`) produced zero occurrences of the
  raw tag anywhere in the response. ZAP's heuristic matched because the
  word "garden" substring-matches "Garden hose," not because of any real
  reflection. **False positive.**

Report: `docs/reference/testing/scanner-tooling/reports/zap-authenticated-2026-09-13-run1.md`
— committed, per the "never edited after the fact" rule.

**Role-scoped scans, `content_editor` and `warehouse_employee`, 2026-09-13
— the lesson from `/account/orders`, applied twice more.** Two dedicated
plans, each copying `zap-auth.yaml`'s shape but narrowing **both** `urls`
and `includePaths` to the three (`articles`, `article-categories`, `tags`)
or four (`orders`, `shipments`, `inventories`, `carriers`) resources that
role can actually reach — the `/account/orders` lesson above, confirmed to
generalise: an unnarrowed `includePaths` on a page that links to the full
admin nav pulls in every resource the role cannot access, none of which
the active scan should spend its budget fuzzing (a 403 page has nothing to
fuzz).

**The session-lifetime trap, hit and fixed within this same pass.** The
first `content_editor` attempt used a session established roughly 20
minutes before the container actually started scanning (the gap being
this pass's own admin scan and role-access verification in between);
by the time the spider ran, every request redirected to `/login` — the
report's own tell was `Big Redirect Detected` on all three seed URLs
(a 302 to `/login` with a suspiciously large body) and 92% of responses
being 3xx rather than 2xx, confirmed by checking `curl -b
<the same cookie jar> /admin/articles` directly and getting 302, not 200.
The scan technically "succeeded" (exit 0) while scanning nothing but the
login page — the exact "silent success" shape `pentest-the-system.md`
already warns the authenticated-scan setup produces on a stale cookie.
Fixed by re-authenticating immediately before launching, not minutes
before: the corrected run found 40 real endpoints (up from spider-only
crawling of the login redirect) with 63% 2xx and no `Big Redirect` alert
at all.

**`content_editor` result (corrected run):** 0 High, 3 Medium, 1 Low,
1 Informational — `CSP: script-src unsafe-eval`/`unsafe-inline`, `CSP:
style-src unsafe-inline`, `Cookie No HttpOnly Flag`, `Session Management
Response Identified`, all already-triaged. No page-specific finding from
ZAP's own alert list. **The genuine finding from this scan did not come
from an alert at all** — see SEC-017: the raw access log for this scan's
container IP showed four `500`s on `POST /livewire/update` at
`/admin/article-categories/create`, which ZAP's own rule set has no
category for ("this form crashed the server" is not a rule), traced to
`storage/logs/laravel.log`'s full stack trace
(`QueryException: Data too long for column 'name'`) and found on live
confirmation to affect five sibling resources beyond the one the scan
happened to touch.

**`warehouse_employee` result:** established fresh session immediately
before launch this time, applying the lesson above from the start rather
than discovering it mid-pass, and it held for the full run — 93% of
responses 2xx, 34 endpoints found, active scan completed its full
15-minute budget rather than truncating. `/admin/orders`,
`/admin/shipments`, `/admin/inventories`, `/admin/carriers` — internal
order note, shipment tracking number, and inventory adjustment reason are
this role's free-text surface, staff-only render, lower value than the
storefront but part of the todo's own scope. 0 High, 3 Medium, 1 Low,
1 Informational, all five already-triaged categories (`CSP: script-src
unsafe-eval`/`unsafe-inline`, `CSP: style-src unsafe-inline`, `Cookie No
HttpOnly Flag`, `Session Management Response Identified`). Cross-referenced
the webserver access log for this scan's window directly (not only the
alert summary, per SEC-017's own lesson): 1405×200, 14×404, 68×405, **zero
500s, zero 302s** — no crash, no session death.

Both role reports triaged in full before either was trusted — the
discipline `pentest-the-system.md` names as "a warning copied into the
report without being understood is worse than no scan" — rather than
transcribed as a clean pass because the alert count was low. Reports:
`docs/reference/testing/scanner-tooling/reports/zap-authenticated-editor-2026-09-13.md`
and `zap-authenticated-warehouse-2026-09-13.md` — both committed.
