# Security tooling — what is configured, what it covered, what it found

The factual companion to two other pages. `how-to/pentest-the-system.md` is
the *procedure*; `reference/security-testing.md` is the *findings*, one
bug-bounty entry per issue. This page is the *inventory*: which tools are
wired up, how each is configured, exactly what surface they reached, and what
came out — so a reader can judge the coverage rather than take a summary on
trust.

Everything below is measured, from tool output and the web server's own
access logs. Where a number is a limit rather than an achievement, it is
labelled as one; the "Limits" section at the end is not an afterthought but
the part that makes the rest meaningful.

**Date of record:** 2026-09-03. A security claim has a date, and this one is
about the code as it stood then.

## The toolchain

| Tool | Version | Runs where | What it is for |
|---|---|---|---|
| **OWASP ZAP** | `zaproxy:stable` (2.17.0) | Docker container on the app's own network | DAST — passive header/config analysis, and active injection attack |
| **Playwright MCP** | `@playwright/mcp` 0.0.80 | Host, driving Chromium | Live exploitation of suspected findings; verifying a fix in a real browser |
| **Chromium** | Chrome for Testing 153.0.8010.12 | `~/.cache/ms-playwright/` | The browser Playwright drives |
| **`composer audit`** | Composer 2, in the `app` container | Container | Known CVEs in PHP dependencies |
| **`npm audit`** | npm, on the host | Host | Known CVEs in JS dependencies |
| **Larastan** | `larastan/larastan ^3.10` | Container | Static types; catches a wrongly-typed security constant at compile time |
| **Pest** | Pest 4 | Container | The regression tests that pin each fix |

Two things deliberately **not** installed, so the absence is not mistaken for
an oversight — the reasoning is in `how-to/pentest-the-system.md`:

- **sqlmap** — fuzzes for string-concatenated SQL, a bug class ruled out by
  reading every query path (parameter binding throughout, `ORDER BY` behind
  an allow-list). It would spend hours confirming a negative.
- **Metasploit** — targets known CVEs in deployed *services*, not a bespoke
  application's business logic, which is where this codebase's real findings
  were. Relevant against the production host, not this app.

### Why ZAP runs as a container on the app network

The scanner reaches the application by its **internal service name**
(`http://webserver:80`), not `localhost:8080`. The published port is a
host-side mapping the ZAP container does not share. This is the single
environment-specific fact every scan command depends on.

## Configuration, as actually used

### Baseline scan — passive, ~1 minute

Spider plus passive rules. Cheap enough to run on every deploy, and it
catches the entire header/misconfiguration class.

```bash
docker run --rm --network online_shop_teamb_default \
  -v "$(pwd)/scratchpad:/zap/wrk:rw" \
  ghcr.io/zaproxy/zaproxy:stable \
  zap-baseline.py -t http://webserver:80/catalogue -r zap-baseline-report.html
```

### Full active scan — unauthenticated

The baseline's rules **plus** attack payloads fired at every discovered
parameter. Attacks the target, so: own dev instance only.

```bash
docker run --rm --network online_shop_teamb_default \
  -v "$(pwd)/scratchpad:/zap/wrk:rw" \
  ghcr.io/zaproxy/zaproxy:stable \
  zap-full-scan.py -t http://webserver:80/catalogue \
    -r zap-full-report.html -x zap-full-report.xml
```

### Authenticated scan — an Automation Framework plan

The only configuration that reaches the admin panel. Run with
`zap.sh -cmd -autorun /zap/wrk/zap-auth.yaml`. The full plan and the traps
that make it fail *silently* are in `how-to/pentest-the-system.md`; the
load-bearing parameters are:

| Setting | Value | Why |
|---|---|---|
| `includePaths` | `http://webserver(:80)?/.*` | ZAP normalises away the default port. A pattern carrying `:80` matches **nothing** — the spider reports "found 0 URLs" and the run still exits 0 |
| `excludePaths` | `…/storage/.*` | Otherwise the scan spends most of its runtime probing 182 product images for `.bak`/`.zip` variants at ~77 req/s |
| `excludePaths` | `…/logout.*` | A spider that follows the logout link ends its own session; every later request is scanned as a guest |
| Session | Injected via an `httpsender` script | The login is Livewire (`POST /livewire/update` with a component snapshot), which ZAP's form-based auth cannot reproduce |
| `threadPerHost` | `2` | `AuthenticateSession` regenerates the session under concurrent load. At default parallelism the injected cookie went stale mid-scan — see the measurement below |
| Output dir | writable by uid 1000 | ZAP runs as `zap`; a root-owned directory makes the plan exit **3** on `Permission denied` *after* scanning successfully, losing every report |

**The `threadPerHost` change is measured, not guessed.** Admin-route status
codes from the web server's own logs, same scan configuration otherwise:

| | Default parallelism | `threadPerHost: 2` |
|---|---|---|
| `/admin/*` → `200` (authenticated) | 92 | **587** |
| `/admin/*` → `302` (session lost) | 4,550 | **3** |

At default parallelism 98 % of admin requests bounced off the login
redirect — a run that *looks* authenticated and is not.

## Coverage — what was actually reached

From the web server's access logs across every scan run, not from a summary.

### The application surface

| Area | Distinct paths returning `200` |
|---|---|
| Product detail pages | 156 |
| Admin panel pages | 160 |
| Journal / article pages | 17 |
| Storefront pages (`/catalogue`, `/cart`, `/checkout`, `/contact`, `/about`, `/account/password`, confirmation) | 7+ |
| **Total distinct paths served `200`** | **533** |

**All 20 Filament admin resources were reached while authenticated:**
`article-categories`, `articles`, `attributes`, `attribute-values`, `brands`,
`carriers`, `contact-messages`, `coupons`, `inventories`,
`newsletter-subscribers`, `orders`, `payments`, `product-categories`,
`product-reviews`, `products`, `roles`, `shipments`, `tags`, `users`, plus
the dashboard.

### The probe surface

**12,705 distinct URL paths** were requested in total. The gap between that
and the 533 above is the scanner's own discovery work — attack payloads and
guesses at paths that do not exist:

- `/productsbackup`, `/products - Copy`, `/Copy of products`, `.bak`, `.zip`,
  `.old` variants — backup-file discovery, all `404`
- directory-traversal attempts against `/images/`, `/storage/`, `/js/` — all
  refused `403` by nginx
- injection payloads appended to every discovered parameter

Those 12,172 non-`200` requests are not "pages tested"; they are the attack
itself. Counting them as coverage would be dishonest, which is why the two
numbers are kept apart here.

### The final authenticated run

The last run in the sequence, after every fix above was applied — the number
that describes the system as it stands rather than as it was found.

| | Value |
|---|---|
| URLs discovered | 761 (traditional spider) + 135 (AJAX spider) |
| Endpoints scanned | 376 |
| Active scan duration | 20m00s |
| **High-risk alerts** | **0** |
| Medium | 4 |
| Low | 4 |
| Informational | 2 |

**Two alerts cleared between the previous run and this one, each confirming a
fix rather than merely disappearing:**

- `X-Content-Type-Options Header Missing` — **gone**, confirming SEC-007's
  nginx `add_header`.
- `Big Redirect Detected` — **120 instances down to 3**, confirming the
  `threadPerHost: 2` session fix. The remaining 3 are the unauthenticated
  probes that *should* redirect.

`CSP: Wildcard Directive` had already cleared in the previous run, confirming
SEC-006's `img-src` fix.

**The 4 Medium are all known and previously assessed**: three are the
`unsafe-eval`/`unsafe-inline` CSP trade-off that SEC-006 measured and
documented as unavoidable on this stack, and one is `Sub Resource Integrity`
against the Vite dev server, which does not exist in a production build.

**One informational alert was investigated rather than dismissed.** `User
Controllable HTML Element Attribute (Potential XSS)` fired on
`/catalogue?category=garden` — ZAP's own wording is "try injecting special
characters to see if XSS might be possible," a hint rather than a finding.
Tested by hand with two payloads:

```
?category="><script>alert(1)</script>   → not reflected
?category=ZZQUOTE"ZZ                    → rendered as ZZQUOTE\&quot;ZZ
```

The quote is HTML-escaped to `&quot;` *and* backslash-escaped inside the
Livewire JSON payload. Blade's `{{ }}` escaping holds; there is no attribute
breakout. **Confirmed false positive.**

### Rule coverage

The unauthenticated full scan ran **136 active rules**. Every injection class
passed *under attack*, not by inspection:

| Class | Rules |
|---|---|
| SQL injection | 7 — generic plus time-based blind for MySQL, PostgreSQL, Oracle, MsSQL, Hypersonic |
| Cross-site scripting | 5 — reflected, persistent (3 variants), DOM-based |
| Path traversal / remote file inclusion | 2 |
| OS command injection | 2 — direct and time-based |
| Server-side template injection | 2 — direct and blind |
| XXE, XSLT, XPath, LDAP, SSI, NoSQL | 6 |

## Results

### What was found, and fixed

Seven entries are recorded in `reference/security-testing.md`, in bug-bounty
form with reproductions. In short:

| ID | Finding | Severity | Found by | Status |
|---|---|---|---|---|
| SEC-001 | Draft and embargoed articles readable by any visitor | High | Static review | **Fixed**, regression-tested |
| SEC-002 | Another customer's order serial number disclosed | Medium | Static review | **Fixed**, regression-tested |
| SEC-003 | `league/commonmark` 2.9.0 — 4 advisories, no reachable path | Low | `composer audit` | Open; patch on next update |
| SEC-004 | No security headers on any response | Low | ZAP baseline | **Fixed** (app); Forge equivalent owed |
| SEC-005 | Full active scan — no injection vulnerability | — | ZAP full scan | Clean |
| SEC-006 | No CSP or CORP; a wildcard in the first fix attempt | Low | ZAP + review | **Fixed**, regression-tested |
| SEC-007 | Static assets carried no security headers | Low | ZAP authenticated | **Fixed** (app); Forge equivalent owed |

**The two that mattered most — SEC-001 and SEC-002 — were found by static
review, not by any scanner**, and no scanner would have found either: both
required understanding that a Livewire public property is client-writable and
what it gates. Conversely the scanner found the header gaps that review had
under-weighted, and caught a wildcard in a fix that review had waved through.
Neither method is redundant.

### What held under attack

Verified, not assumed. Each of these was probed and refused:

- **No SQL injection**, across 376 authenticated endpoints and 7 rule
  variants. Every query binds its parameters; `ORDER BY` sits behind an
  `array_key_exists` allow-list.
- **No XSS** — the view layer contains zero `{!! !!}`; rich text is sanitised
  through `Article::safeContent()` (ADR-0015).
- **No path traversal, RFI, OS command injection, SSTI, or XXE.**
- **Authorization holds**: 120 alerts that ZAP reports as "Big Redirect" are
  `/admin/*` correctly returning `302 → /login` to an unauthenticated
  request, and 230 responses of `403` are nginx refusing directory access.
  Both are the application working.
- **Dependencies**: `npm audit --omit=dev` — 0 vulnerabilities.
- **Regression tests**: every fix is pinned by a Pest test that was **verified
  by removal** — the guard is deleted, the test is watched going red, and the
  guard restored. A test never observed failing proves nothing.

## The CSP `unsafe-*` alerts will not go away, and why that is correct

Three of the four remaining Medium alerts are the same finding in different
clothes: `CSP: script-src unsafe-eval`, `CSP: script-src unsafe-inline`, and
`CSP: style-src unsafe-inline`. They are permanent under the current
frontend, they are **not** an unfinished task, and anyone reading this report
will ask about them — so the reasoning is recorded here rather than left in a
commit message.

### The constraint is measured, not assumed

Two facts about this application's frontend, both checked against the
rendered page rather than inferred from the framework's documentation:

- **Alpine evaluates its attribute expressions at runtime.**
  `x-data="{ open: false }"`, `x-on:mouseenter="open = true"` and
  `wire:click="$set('categorySlug', 'beauty')"` are strings that become
  JavaScript when the component initialises. That is `eval` by another name,
  and it requires **`unsafe-eval`**. Verified in a browser: with the
  directive withheld, Alpine components do not initialise at all.
- **The rendered pages carry inline styles and scripts.** 176 inline
  `style="…"` attributes on the catalogue alone, plus inline `<script>` and
  `<style>` blocks. Those require **`unsafe-inline`**.

A policy forbidding either does not harden this application. It stops it
working — no interactive navigation, no filters, no cart. **A CSP that breaks
the site is not a stricter CSP; it is an outage.**

### What the policy still buys

The honest framing is that `script-src` is permissive and four other
directives are not. Those four hold regardless, and each blocks a real attack
class:

| Directive | Blocks |
|---|---|
| `frame-ancestors 'none'` | Clickjacking — and unlike `X-Frame-Options`, this is the directive modern browsers actually honour |
| `object-src 'none'` | Plugin and object embedding |
| `base-uri 'self'` | An injected `<base>` tag silently rewriting every relative URL on the page |
| `form-action 'self'` | An injected form posting credentials to an attacker's origin |

Those are the four pinned by `tests/Feature/Support/SecurityHeadersTest.php`,
so a future loosening of `script-src` cannot quietly take them with it.

### What would actually remove the alerts

Not a header change — frontend work, and a substantial amount:

1. Replace every inline `style="…"` attribute with a class.
2. Move every inline `<script>` block into a bundled file, or give each one a
   per-request nonce.
3. Adopt Alpine's **CSP build**, which trades expression syntax for
   `eval`-free evaluation and requires rewriting every `x-data` and `x-on:`
   expression in the codebase.

That is a real project with a real regression risk, weighed against alerts
whose exploitability depends on an XSS hole that the scan and the review both
failed to find. It is recorded as a deliberate, costed decision — not an
oversight, and not something to "just fix" in a header.

## False positives, and why they are recorded rather than removed

Four alerts across the scans are false positives. They are kept in the
reports, each with the evidence that settles it, because **a suppressed alert
and an unexamined one look identical six months later.** Deleting them would
make the report look cleaner and be worth less.

| Alert | Why it is not a finding | Evidence |
|---|---|---|
| **`Cookie No HttpOnly Flag`** | Flags `XSRF-TOKEN`, which Laravel's CSRF double-submit pattern **requires** JavaScript to read so it can echo `X-XSRF-TOKEN`. Making it `HttpOnly` would break CSRF protection, not improve it. The cookie that matters — `amazoff-session` — does carry `HttpOnly` | `curl -I` shows `amazoff-session=…; httponly` and `XSRF-TOKEN=…` without it |
| **`Cross-Domain JavaScript Source File Inclusion`** and **`Sub Resource Integrity Attribute Missing`** | Both point at `http://localhost:5173/…` — the **Vite dev server**. A production build emits bundled, same-origin assets and no dev server exists there | The flagged URLs are `@vite/client` and `resources/js/app.js` on port 5173 |
| **`Information Disclosure - Sensitive Information in URL`** | Fires on `/login?email=zaproxy%40example.com&password=ZAP` — **ZAP's own fuzzer URL**, not a request the application generates. The real login is a Livewire `POST`, which never puts credentials in a query string | The URL appears only in ZAP's request log, never in the application's routes |
| **`User Controllable HTML Element Attribute (Potential XSS)`** | ZAP's own wording is "try injecting special characters to see if XSS **might** be possible" — a hint to investigate, not a finding. Investigated and refuted | `?category="><script>alert(1)</script>` is not reflected; `?category=ZZQUOTE"ZZ` renders as `ZZQUOTE\&quot;ZZ`, HTML-escaped *and* backslash-escaped inside the Livewire payload |

**The general rule this project follows:** a scanner's output is raw material,
not findings. Every alert gets a decision — real, false positive, or
already-known — and a false positive gets the same written justification a
real finding gets. The `Cookie No HttpOnly` case is the one worth
remembering: transcribed blindly it reads as a session-security bug, and
"fixing" it would have broken CSRF protection. **The scanner cannot know the
framework's design; a human has to.**

## Limits — what this does *not* claim

Stated plainly, because a coverage claim without its limits is not
verifiable, and an informed reader will find them anyway.

- **Not "everything was tested."** 533 application paths were reached and
  attacked; the application has more states than paths — a form submitted
  with 50 different payload shapes is one path.
- **Admin coverage is partial.** All 20 resources were reached, but the
  *index* pages far more thoroughly than every edit/create/view sub-page.
- **One role was scanned.** The authenticated run used `admin@example.com`.
  `content_editor` and `warehouse_employee` were probed by hand against every
  admin route (the role matrix in `security-testing.md`), but never crawled
  by a scanner.
- **No production scan.** Everything here ran against the local Docker stack.
  Production runs on Forge with its own nginx and PHP configuration, so the
  two nginx-level fixes (SEC-004, SEC-007) do **not** reach it — see the
  pre-deploy checklist in `how-to/pentest-the-system.md`.
- **Courier integration is unbuilt** (§37 #12–15), so its credential handling
  and SSRF surface are unreviewed — there is nothing there yet to review.
- **No business-logic fuzzing.** A scanner cannot know that an order's total
  should equal its items, or that a coupon should not be redeemable twice.
  Those invariants are covered by the Pest suites (`tests/Concurrency` in
  particular), not by ZAP.
- **This is a point-in-time result.** New code is new surface. The
  `#[Locked]` class of bug recurs the day someone adds a public Livewire
  property without it.
