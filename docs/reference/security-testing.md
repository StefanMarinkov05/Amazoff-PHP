# Security testing — what was probed, what held, what did not

The third page in the family `stripe-testing.md` and `browser-testing.md`
belong to: an honest record of a security pass, including what it could not
reach. `explanation/security-model.md` states the model this checks against;
this page is the result of checking.

A pass here is a claim about the code as read on one date, plus whatever
runtime probing the environment allowed that day. It is not a claim that the
application is secure.

## Method, and its limits

Static review against the whole request path — routes, middleware,
Livewire components, policies, models, raw SQL, and the view layer — with
`CLAUDE.md`'s "Security rules that are ours" as the checklist, since those
are the rules the framework does not enforce on its own.

**Runtime probing was blocked** for most of 2026-09-03 by a host fault
(see `how-to/troubleshooting.md`, "Containers healthy, every request 504").
That fault was repaired on 2026-09-03 (a reboot; see the troubleshooting
entry), and both code findings below were then **confirmed by live
exploitation** against the running app with a real browser driving Livewire
— not merely read from source. The reproduction for each is recorded with
it.

Dependency scanning did complete once the host was repaired.

| Scan | Result |
|---|---|
| `npm audit --omit=dev` | 0 vulnerabilities |
| `composer audit` | **4 advisories, 1 package** — see below |

## Findings

Three issues. Two are exploitable IDOR/authorization bypasses in this
project's own code, both confirmed by live exploitation on 2026-09-03; both
share one shape — a client-writable Livewire public property that a query
downstream trusts. The third is a dependency advisory with no reachable path
today. Each is written in bug-bounty report form: **Finding → Reason →
Reproduction → Fix → Logic for future pentests.**

Severity uses CVSS-style qualitative bands (Critical / High / Medium / Low /
Info), rated for this application in its current state, not in the abstract.

---

### SEC-001 — Broken access control: any visitor can read unpublished and embargoed articles

**Severity:** High · **Type:** IDOR / broken function-level authorization
(OWASP A01) · **Component:** `app/Livewire/Journal/ArticleDetails.php` ·
**Status:** **Fixed** (confirmed by exploitation, then by regression test)

**Finding.** An unauthenticated visitor can read any article regardless of
its publication state — drafts (`status = draft`), and articles scheduled
for future publication whose embargo date has not arrived. The article
detail page checks visibility only when it first mounts; the property that
selects which article to render is client-controlled and re-read on every
subsequent request, so the check is bypassed by changing that property after
mount.

**Reason (root cause).** `mount()` enforces the gate correctly:

```php
abort_unless(
    Article::query()->visible()->whereKey($article->getKey())->exists(),
    404
);
$this->articleId = $article->getKey();
```

but the computed property that actually feeds the view does not re-apply it:

```php
#[Computed]
public function article(): Article
{
    return Article::query()
        ->with(['author', 'articleCategory', 'tags'])
        ->findOrFail($this->articleId);   // ← no ->visible()
}
```

`public int $articleId` carries no `#[Locked]`. In Livewire 3, every public
property is serialised to the client and re-hydrated from the client payload
on **every** component update — so `$articleId` is attacker-controlled after
the first render. The authorization decision (`->visible()`) and the data
fetch (`findOrFail`) read the same untrusted value at two different times,
and only the first read is guarded. `Article::visible()` gates both
`status = Published` **and** `published_at <= now()`, so the bypass exposes
drafts and embargoed content alike.

**Reproduction.**

1. Seed: `migrate:fresh --seed`, then `DemoSeeder`, `ContentReferenceSeeder`,
   `DemoArticleSeeder` (yields 16 published + 8 non-public articles).
2. Identify a draft id (e.g. 4, `status=draft`) and a scheduled id
   (e.g. 6, `status=scheduled`) via
   `Article::where('status','!=','published')->pluck('status','id')`.
3. As an unauthenticated guest, open a *published* article, e.g.
   `/journal/choosing-the-right-laptop-for-work-and-travel` (id 1).
4. Overwrite the component's `articleId` and force a server round-trip — the
   browser console is the simplest way to emit the crafted Livewire update:

   ```js
   const w = window.Livewire.all()
     .find(c => c.name === 'journal.article-details').$wire;
   w.set('articleId', 4);   // draft
   await w.$refresh();
   ```

5. **Observed:** the URL is unchanged but the rendered `<h1>` and full body
   become the draft's ("Choosing Between a Standing Mixer and a Hand Mixer"),
   including the author byline. Repeating with `articleId = 6` renders the
   embargoed article ("How Much Torque Do You Actually Need in a Power
   Tool").

   ![Draft article rendered to an unauthenticated visitor](../assets/exploit-1-draft-article-leak.png)

**Fix.** Re-apply the visibility scope wherever the id is *used*, not only
where it is first set — so the guard travels with the value:

```php
#[Computed]
public function article(): Article
{
    return Article::query()
        ->visible()                       // ← the load-bearing change
        ->with(['author', 'articleCategory', 'tags'])
        ->findOrFail($this->articleId);
}
```

Add `#[Locked]` to `$articleId` as defence in depth, but the scope is the
half that must land: it fails safe even if the property is later unlocked or
a second code path sets it. A regression test belongs in
`tests/Feature/Livewire/` asserting that setting `articleId` to a draft's id
and re-rendering 404s (and added to a CI shard in the same change).

**Remediation applied (2026-09-03).** Both mechanisms are now in
`ArticleDetails`: `article()` re-applies `->visible()`, and `$articleId`
carries `#[Locked]`. Proven by
`tests/Feature/Livewire/ArticleDetailsVisibilityTest.php` (5 cases) — the
file sits under a directory already in `.github/workflows/ci.yml`'s shard 2,
so it runs in CI. Each guard was verified by removal: deleting `->visible()`
turns the scope case red (a draft resolves instead of 404ing), deleting
`#[Locked]` turns the tampering case red.

**Logic for future pentests.** This is the **check-at-mount / use-later**
class, specific to any stateful component framework (Livewire, Blazor,
server-driven React) where "the id I validated" and "the id I query" are the
same client-owned field read at two moments. Hunt for it by listing every
`public` property that reaches a query, then checking whether the
authorization predicate is re-evaluated on *every* read of it or only in
`mount()`/constructor. A guard in `mount()` and a bare `findOrFail` in a
`#[Computed]`/render path is the tell. `grep` for `#[Computed]` methods whose
query lacks the scope the mount used.

---

### SEC-002 — IDOR: order serial numbers of other customers are disclosed

**Severity:** Medium · **Type:** IDOR / broken object-level authorization
(OWASP A01) · **Component:** `app/Livewire/Checkout/CheckoutPage.php` ·
**Status:** **Fixed** (confirmed by exploitation, then by regression test)

**Finding.** During checkout, the payment step renders an order's serial
number read from an unscoped `Order::find()` on a client-controlled id. A
visitor can point it at an order they do not own — including another
customer's — and read that order's serial number. Serial numbers are
sequential (`ORD-000001`, `ORD-000002`, …), so this is the enumeration
primitive that `OrderConfirmation` was specifically built to deny, now
reachable on a second path.

**Reason (root cause).**

```php
public ?int $orderId = null;        // client-writable, no #[Locked]
public ?string $clientSecret = null; // client-writable, no #[Locked]

#[Computed]
public function order(): ?Order
{
    return $this->orderId === null ? null : Order::find($this->orderId);
}
```

`Order::find()` is unscoped — no `->where('user_id', ...)`, no session-claim
check — on an id the client controls. The template prints
`{{ $this->order?->serial_number }}` behind an `@elseif ($clientSecret)`
branch, and `$clientSecret` is *also* a client-writable public property, so
one crafted payload both opens the render branch and selects the victim
order. The sibling component `OrderConfirmation` solves this exact problem
correctly (scoped to `auth()->user()->orders()` or a session claim, 404 not
403 to avoid confirming existence); `CheckoutPage` reintroduces the bare
lookup.

**Reproduction.**

1. Seed as above. Place a COD order as guest A (becomes order id 1). Create a
   second order id 2 owned by `customer@example.com` (a different principal).
2. In guest A's session, on `/checkout`, drive the component:

   ```js
   const w = window.Livewire.all()
     .find(c => c.name === 'checkout.checkout-page').$wire;
   w.set('orderId', 2);                                  // not guest A's order
   w.set('clientSecret', 'pi_forged_to_pass_render_gate'); // any truthy string
   await w.$refresh();
   ```

3. **Observed:** the page renders "Order **ORD-000002** is reserved. Enter
   your card to pay." — the serial number of a different customer's order,
   served to a visitor who does not own it.

   ![Another customer's order serial disclosed at checkout](../assets/exploit-2-order-serial-enumeration.png)

**Why Medium and not High.** Only `serial_number` is printed on this path —
not the address, line items, or totals that make an order-confirmation IDOR
severe. What leaks is the *existence and serial* of an arbitrary order, i.e.
the input to enumeration, not the customer PII itself. It is rated Medium
because the impact is confirmation/enumeration rather than bulk PII
disclosure — but it defeats a control the codebase deliberately built, which
is why it is not Low.

**Fix.** Scope the lookup the way `OrderConfirmation` does, and lock both
properties:

```php
use Livewire\Attributes\Locked;

#[Locked] public ?int $orderId = null;
#[Locked] public ?string $clientSecret = null;

#[Computed]
public function order(): ?Order
{
    if ($this->orderId === null) {
        return null;
    }
    $user = auth()->user();
    if ($user && ($owned = $user->orders()->find($this->orderId))) {
        return $owned;
    }
    return session(OrderConfirmation::SESSION_KEY) === $this->orderId
        ? Order::find($this->orderId)
        : null;
}
```

`#[Locked]` alone would blunt the console reproduction, but the scoped query
is what makes the id safe even if it arrives some other way — the same
reasoning `OrderConfirmation` documents.

**Remediation applied (2026-09-03).** `order()` now scopes to
owner-or-session-claim, and both `$orderId` and `$clientSecret` carry
`#[Locked]`. Proven by four cases added to
`tests/Feature/Payment/CheckoutTest.php` (already in CI shard 2): the
disclosure case (a guest cannot read a foreign order's serial), the lock
case (tampering with either property throws), and two that confirm the
legitimate owner and just-placed-guest paths still resolve. Verified by
removal: reverting `order()` to the bare `Order::find()` turns the
disclosure case red. The full 26-case checkout suite still passes, so the
fix does not regress guest or registered checkout.

**Logic for future pentests.** Same **object-level authorization on a
client-owned id** class as SEC-001, but the tell here is a *second, unhardened
copy* of a query the codebase already secured elsewhere. When one component
(here `OrderConfirmation`) is conspicuously careful about an IDOR, grep for
every other component that loads the *same model by id*
(`grep -rn 'Order::find' app/Livewire`) and check each independently — a
control applied on one path is routinely missing on a sibling path. Also
note the double-gate bypass: when a render branch is guarded by another
public property (`$clientSecret`), that guard is only as strong as that
property is locked; a client-writable flag protecting a client-writable id
protects nothing.

---

### SEC-003 — Vulnerable dependency: `league/commonmark` 2.9.0 (no reachable path)

**Severity:** Low (Informational for this app today) · **Type:** Vulnerable
& outdated component (OWASP A06) · **Status:** Reported by `composer audit`,
exposure verified nil

**Finding.** `composer audit` reports four advisories against
`league/commonmark` 2.9.0, two rated **high** by the advisory database: an
XSS filter bypass in `AttributesExtension`'s `on*` event-handler filter via a
U+000C form feed (`GHSA-f8fg-pg57-v4j8`), and a DoS via crafted code fences,
reference links, and emphasis delimiters (`GHSA-j8pm-gj4c-rq4x`). All four are
fixed in **2.9.1**, a patch release.

**Reason (root cause / reachability).** The package is transitive — pulled by
`laravel/framework` and `laravel-lang/publisher`, named nowhere in
`composer.json`. Every advisory requires attacker-controlled **Markdown**
reaching the CommonMark parser, and this application renders no Markdown
anywhere: `grep -rniE 'commonmark|Str::markdown|@markdown'` over `app/` and
`resources/views/` is empty, and there are no Markdown mailables or
notifications. So the vulnerable code is installed but not on any request
path. Exposure today is a property of what the app does not do yet, not of a
boundary that enforces anything.

**Reproduction.** `docker compose exec app composer audit` — reports "4
security vulnerability advisories affecting 1 package." There is no
application-level reproduction because no route reaches the parser.

**Fix.**

```bash
composer update league/commonmark --with-dependencies
```

Bumps to ≥ 2.9.1. Low-risk (patch release); do it on the next dependency
pass.

**Logic for future pentests.** A transitive dependency advisory is only as
real as its reachability — rate it by whether a request path feeds the
vulnerable function, not by the advisory's headline CVSS. Re-check this one
specifically the moment any Markdown-rendered field ships (a product
description, an article body edited as Markdown, a review): the first such
field turns a nil-exposure advisory into a live stored-XSS on day one, so
"unreachable today" is a note to revisit, not a dismissal. Standing rule:
re-run `composer audit` every dependency change and every pentest pass, and
diff the reachable set, not just the advisory count.

---

### The pattern behind SEC-001 and SEC-002 — `#[Locked]` is absent project-wide

`grep -rn '#\[Locked\]' app/` returns **zero** results. Both exploitable
findings are instances of one missing habit rather than two unrelated slips:

> **Any public Livewire property that holds an identifier, or feeds an
> authorization decision, needs `#[Locked]` *and* a re-check at the point of
> use.**

`OrderConfirmation` already demonstrates the re-check form and documents why
its lookup is scoped and re-evaluated on every render; that reasoning simply
was not carried to `ArticleDetails` or `CheckoutPage`. Fixing the two
findings closes the live holes; adopting the rule above closes the class.

Four other ID-bearing public properties were checked and are **not**
findings today — `ProductDetails::$productId` and `$imageIndex`,
`CartPage::$quantities` and `$couponCode` — because each reaches only public
catalogue data or a validated cart write. They are the same shape, though: a
future non-public column on `products`, or any authorization tied to a cart,
would promote them to findings, so they belong on the watch-list for the next
pass.

## What held

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

## Hardening gaps (not vulnerabilities today)

Neither is exploitable in local dev over HTTP; both matter on first deploy.

- **No security headers.** No middleware sets them and `docker/nginx/*.conf`
  contains no `add_header` at all — so no HSTS, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, or CSP. With Livewire and
  Alpine, a CSP needs designing rather than pasting; the other four are
  one middleware.
- **`SESSION_SECURE_COOKIE` is unset** and absent from `.env.example`.
  `http_only` (true) and `same_site` (lax) are correct; `secure` is what
  keeps the session cookie off plaintext HTTP, and production must set it.

## Not covered

- **Automated scanning.** No ZAP, nikto, or sqlmap run — this pass is static
  review plus targeted live exploitation of the two findings and a
  route-level role sweep, not a fuzzing/scanner pass. Static reading finds
  authorization bugs a scanner cannot see and misses the misconfiguration a
  scanner finds first; a scanner pass remains owed for the second half.
- **Authenticated deep crawl and response-header inspection** against every
  live page were not done — the role sweep probed authorization status codes,
  not each page's rendered content or headers.
- **Filament's own surface** was read at the policy layer, not probed. Its
  form/table plumbing is upstream's to secure.
- **No courier code exists yet** (§37 #12–15 unbuilt), so credential
  handling on that path is unreviewed.
- **Rate limiting outside login.** Registration, contact, and newsletter have
  no throttle noted; not assessed for abuse volume.
