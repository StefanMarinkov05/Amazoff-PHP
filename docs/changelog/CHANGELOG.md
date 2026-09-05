# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). Dates are
when the work happened, not when it was committed — nothing in
`Unreleased` has a git commit yet.

## Unreleased

### Added

- **`demo:seed` — the whole demo dataset in one command.** `DatabaseSeeder`
  seeds only what every environment needs (permissions, roles, carriers,
  staff accounts), so `migrate:fresh --seed` left an empty catalogue and
  loading the demo content meant thirteen `db:seed --class=` invocations by
  hand, in an order where five steps are load-bearing and none of them said
  so at the point of use. The ordering now lives in
  `Demo\DemoDatabaseSeeder`, next to the calls it constrains, and
  `demo:seed --fresh` composes the reset and the load.

  Not wired into `migrate:fresh --seed`, and it must not be: CI wants the
  smallest fixture that exercises the code, and this set is neither small
  nor fast (ADR-0003).

- **One account that holds every order state.** The 140-order distribution
  spreads across 100 factory customers, so any *particular* account ended up
  with between zero and a handful of orders on whatever statuses the shuffle
  happened to hand it — correct for a realistic dataset, useless for a demo
  where the presenter signs in as the one account whose password they know.

  `DemoOrderSeeder::SHOWCASE_ACCOUNTS` pins 18 additional orders on top of
  the distribution, 14 of them on `customer@example.com`, covering **all
  eleven `OrderStatus` cases on that single login** — so the whole lifecycle
  can be walked through without signing out. Both payment methods, three
  different coupons, 1–5 line items, and payment states including
  `partially_refunded` and `refunded`. `admin@example.com` keeps a small set
  so the storefront half of the app is reachable while signed in as staff.

  Additional to the distribution rather than carved out of it — carving
  would silently shrink whichever statuses were borrowed from, and the
  distribution is asserted against the live database after every run.

  **A pinned coupon assignment cost a whole order, twice, before this was
  right.** `CouponFactory` randomizes `usage_limit_per_customer`, `FLAT15`
  came up as 1, and assigning the same code twice to one account meant
  `RedeemCoupon` refused the second — which, because the coupon is applied
  before checkout, took the entire order with it. The run reported 157 of
  158 created and the missing state was only visible by querying. Fixed by
  never repeating a code within one account, rather than by pinning the
  limit and coupling the table to `DemoCouponSeeder`'s factory defaults.

- **`demo:stripe-payments` — real Stripe test intents against seeded
  orders.** Every `stripe_payment_intent_id` in a seeded database was null:
  `DemoOrderSeeder` produces payment rows through the real Actions but no
  Stripe object anywhere. This opt-in command opens real test PaymentIntents
  for end-to-end payment simulation.

  Not part of the seed chain and never should be — same shape as
  `demo:fetch-images`, same reasoning (ADR-0003 makes seeding offline and
  deterministic). It refuses any key that is not `sk_test_`, so a live key
  cannot open real intents against demo orders by accident. It goes through
  `CreateStripeIntent` rather than the SDK, so the run exercises the real
  checkout path — server-side amount, `lockForUpdate` re-read, idempotency
  key, and the `metadata` the webhook matches on. It creates but never
  confirms: confirmation and the resulting webhook are exactly what an
  end-to-end run exists to exercise.

  Verified against the real test API: intents created, amounts matching the
  payment rows to the minor unit, metadata carrying the payment and order
  ids, and `CreateStripeIntent` proven idempotent live (a second call on the
  same payment returns the first intent rather than a second charge).

  **Re-seeding within 24 hours skips some payments, and that is correct.**
  The idempotency key is `payment-intent-{id}`; ids restart at 1 on every
  `migrate:fresh` while Stripe remembers a key account-wide for 24 hours, so
  low ids collide with a previous seed's payments at different amounts. The
  command reports these as skips rather than failures. The key is a
  double-charge defence and was deliberately not weakened to make the demo
  tidier.

### Changed

- **Review and contact-message text moved out of PHP and into JSON.**
  `DemoReviewSeeder` carried a ~40-line `BODIES` const and
  `DemoEngagementSeeder` a ~30-line `CONTACT_MESSAGES` const, both of which
  pushed the actual sampling and approval code off the screen and made a
  wording change a PHP edit. They are now
  `database/fixtures/reference/review-bodies.json` (45 bodies, up from 25)
  and `contact-messages.json` (40 pairs, up from 25) — vocabulary files, the
  same category as `catalogue.json`, not fixtures with a document shape.

  Both loaders fail loudly on a missing file, a malformed document, a rating
  with no bodies, or a pool smaller than the count the seeder writes. The
  silent alternatives are worse than a crash: `array_slice` on a short pool
  returns fewer rows and a missing rating key writes blank review bodies,
  both of which report as a successful seed.

- **The seeded review count now adapts to the eligible pool.**
  `CreateProductReview` enforces §24's verified-purchase rule itself — one
  review per reviewer per product, from a delivered order they placed — so
  the ceiling is the count of unique (reviewer, product) pairs, and that
  moves substantially between runs: 137, 123, 107 and 149 on four
  consecutive seeds. A fixed constant is wrong on nearly every run, warning
  and under-delivering when set high, wasting an eligible pool when set low.

  `TOTAL_REVIEWS` is now an upper bound and the seeder takes 85% of what is
  available, scaling `RATING_DISTRIBUTION`'s weights to match. ~125 reviews
  on a typical run, up from 90, with no warning. The 15% left unreviewed is
  deliberate: a demo where every delivered line item already has a review
  has nothing to point at for the "write a review" path.

- **More seeded content across the board**, verified against the live
  database after a run rather than asserted: 158 orders (was 140), 405 order
  items (was 344), 118 payments, 89 shipments, ~126 reviews (was 90), 32
  contact messages (was 25). Cart line counts and quantities are now
  weighted rather than uniform — most baskets are one or two lines with a
  real tail out to six, because a flat spread makes a six-line order as
  common as a single-line one, which reads as generated data the moment
  anyone scrolls the order list.

- **`how-to/deploy-and-host.md`** — new, deliberately small. A checklist of
  settings that are correct in local dev only by accident of dev's own
  environment (plain HTTP, no real host), starting with
  `SESSION_SECURE_COOKIE` (SEC-013, half closed until this is actually set
  on Forge) and the nginx/PHP-FPM hardening that only applies to this
  repo's Docker config today. Meant to grow one rule at a time as each area
  gets a real deploy target, rather than being discovered by an incident
  after launch.

- **The guest cart now survives sign-in and registration.** `MergeGuestCart`
  had existed since the cart slice — built, documented, and covered by two
  concurrency tests — with **no caller**. A customer who filled a basket as a
  guest and then signed in found it empty, and the guest row orphaned.
  Confirmed live on 2026-09-04 while testing something else.

  `App\Support\MergeCartOnAuthentication` is the caller, used by both
  `Login` and `Register`, and it exists as its own class for one reason: the
  **ordering**. A guest cart is keyed on `session_id`, and both call sites
  regenerate the session immediately after authenticating — which they must,
  since the pre-login id is what a fixation attack plants. Regeneration
  issues a new id with nothing carrying the old forward, so the obvious
  implementation (merge after login) looks up an id that has never had a
  cart, finds nothing, and *reports success*. The basket is lost silently.

  So the class splits into `capture()`, called before regeneration, and
  `apply()`, called after — making the constraint visible at both call sites
  rather than a comment nobody re-reads.

  **A failed merge never fails the login.** Someone who has proved their
  identity is signed in even if their basket cannot be folded in; the
  alternative is an account locked out by a cart bug. `apply()` re-reads the
  captured row and returns quietly if it has gone — the reachable case being
  a second tab finishing its own merge first.

  8 tests, proven able to fail: **moving the capture back after
  `session()->regenerate()` turns 4 of the 8 red** — exactly the four
  involving a guest basket — while the four that do not (no-cart sign-in,
  deleted-cart tolerance, another session's cart, and registration, which
  was not reverted) correctly stay green. That split is what shows each case
  targets what it claims. Also verified end to end in a real browser: item
  added as a guest, sign-in, and the item present afterwards with the guest
  row consumed and `expires_at` cleared.

- **The nine missing storefront pages, closing every footer 404.** All six
  footer "Help" and "Legal" links pointed at routes that did not exist,
  confirmed live across three separate passes; `/faq` was in §4–5 with no
  link at all. Every one of them now resolves.

  **Six informational pages** — `/delivery`, `/payment-information`, `/faq`,
  `/terms`, `/privacy`, `/cookies` — as plain `Route::view()`s, not Livewire
  components: they hold no state and run no query, so a component would be
  pattern-following (ADR-0014, the reasoning `/about` already follows). A
  shared `<x-site.prose-page>` carries the heading, standfirst, typography
  and back-link, so implementation standard #8 ("repeated markup extracted
  into components") stays true as they were added rather than six
  near-identical files diverging at the edges.

  The copy is written against what this system actually does rather than
  boilerplate: the cookie policy names only the session and CSRF cookies
  plus Stripe's, and says outright that there is no analytics or advertising
  cookie — which is why no consent banner exists. The privacy page says a
  deletion request does *not* erase past orders, because the accounting
  obligation outlives it (`explanation/gdpr.md`). The payment page explains
  3-D Secure and the "still confirming" state a customer can genuinely hit
  when the webhook lands after the redirect.

- **`/orders/track` — public order tracking (§4–5), the page CLAUDE.md's
  security rules already assumed existed.** Requires order number **and**
  email, matched in a *single* `where` rather than looked up by serial and
  then compared: a two-step lookup would answer "does this serial exist"
  separately from "is this the right email", which is precisely the
  enumeration oracle the rule exists to prevent.

  One refusal message for all three failure modes — no such order, wrong
  email, right order with the wrong email — for the same reason `Login`
  gives one message for a bad address and a bad password. Rate limited at 5
  attempts, keyed on IP alone: keying on the serial too would let an
  attacker walk the sequential range at 5 attempts *each*, which is not a
  limit. `#[Locked]` on the entitlement property, per the standing rule from
  SEC-001/SEC-002.

  It deliberately shows status, dates and a total — **no address, phone,
  name or line items**. Email possession is a weaker claim than an
  authenticated session, so it unlocks correspondingly less; the order
  contents stay behind ownership.

- **`/account/orders` — order history (§19, §26).** Every query starts from
  `auth()->user()->orders()`, never `Order::query()`. Eager-loads `payment`
  and `orderItems`, which the list renders — without both it is one extra
  query per row, which renders correctly and is invisible until the order
  count grows. Linked from the account menu in both the desktop and mobile
  headers.

  No per-order detail route: a row links to `checkout.confirmation`, which
  already re-checks ownership on every render. A second detail page would
  mean a second entitlement check to keep correct.

  **14 tests, both suites proven able to fail.** Removing the email half of
  the tracking query turned 2 of 9 red (the wrong-email refusal, and the
  disclosure test — which starts leaking once a wrong email succeeds), with
  the other 7 correctly unaffected since they do not depend on that half.
  Replacing `$user->orders()` with `Order::query()` turned 3 of 5 red; the 2
  that held are the success control and the auth redirect, neither of which
  touches the scoping. Both splits are the evidence that each test targets
  what it claims.

- **Phase 4 of the interactive UI testing record — the admin panel.**
  `reference/testing/ui-testing/phase-4-admin-panel-clickthrough.md`, closing the gap
  all three earlier phases named in their own "not covered" sections: the
  panel's own forms and actions, driven rather than read.

  §37 #18 is now verified across **all 19 admin resources × 4 accounts**
  (the earlier sweep covered 13 routes), extending the role matrix in
  `reference/testing/security-testing.md`. Each role reaches exactly what the
  permission catalogue grants and nothing else. The two 200s that were added
  are the point of the extension: `content_editor` holds `viewAny_tag` and
  `viewAny_article_category`, so a 403 there would have been a *missing
  grant* rather than a leak.

  The article status menu was confirmed **enforced, not merely hidden**, by
  calling `PublishArticle` directly in three configurations — an illegal
  move by an authorized actor (refused by the matrix), a legal move by an
  unauthorized one (refused by the policy), and a legal move by the right
  actor (**accepted**). The third is the control, and it is what makes the
  first two mean anything.

  **A method note worth keeping**, because it produced a false pass
  mid-session: the first price-abuse table reported all five malformed values
  "refused", which read as clean. They were refused on a missing `sku` — the
  probe's payload used the wrong key shape, so the price column was never
  reached. Only the control row, which is *required to pass*, exposed it. A
  probe that never reaches the code under test is indistinguishable from a
  defence that works, so every table in that document now carries a control.

  Two harness notes: Filament's destructive actions open a confirmation
  modal, and a driver that awaits such a call never returns (one probe hung
  for the full 30-minute MCP idle timeout), so every write was run through
  the Action the panel calls, inside a rolled-back transaction; and one live
  `Delete` click was refused by auto-mode's classifier and deliberately not
  retried.

- **Stripe integration hardened against a review from Stripe's own tooling.**
  The `stripe_implementation_planner` MCP tool endorsed the architecture —
  its decision tree terminates at "Elements with the Payment Intents API",
  which is what was built — and a documentation cross-check surfaced four
  real gaps, now closed:

  - **The API version is pinned.** `AppServiceProvider` builds
    `StripeClient` with `stripe_version` set to the installed SDK's own
    `ApiVersion::CURRENT`, rather than a hand-typed string that could drift
    from what is actually installed. It matters here specifically because
    `HandleStripeWebhookEvent` reads payload fields positionally, and a
    webhook endpoint's version is fixed at creation in the dashboard.

  - **A blank `STRIPE_WEBHOOK_TOLERANCE` can no longer disable replay
    protection.** `env()` on a present-but-empty var returns `''`, `(int)''`
    is `0`, and because the config key then *exists*, `config(..., 300)`'s
    default never fires. stripe-php guards its recency check with
    `if ($tolerance > 0)` — so zero does not reject everything, it skips the
    check, leaving a captured request replayable indefinitely. Floored with
    `max(60, …)` in both `config/services.php` and, so it is enforceable and
    testable, at the point of use in the middleware. Verified by removing
    the floor: a year-old replayed request was accepted with a 200.

  - **The paid-amount guard now checks currency too**, and no longer falls
    back to `amount`. The docblock had claimed a currency check the code did
    not perform. The fallback was worse than useless: `amount` is the
    *requested* figure that `CreateStripeIntent` itself set, so falling back
    to it made the guard compare our own number against itself and pass
    regardless of what was actually captured. `amount_received` only —
    Stripe documents it as "Amount that this PaymentIntent collects".

  - **`.env.example` had two conflicting Stripe blocks**, the earlier one
    labelled "ADR-0006 — hosted Checkout" (wrong ADR, and wrong integration
    shape). Later keys won silently. Removed.

- **ADR-0016 records the Payment Intents decision.** Stripe's current docs
  recommend Checkout Sessions by default, so building on Payment Intents is
  a deliberate divergence and now reads as one. The reason is that
  server-recalculated totals, coupon caps, stock reservation, VAT snapshots,
  and COD all happen *before* an intent exists — handing checkout state to
  Stripe would mean reserving stock after taking the money.

- **Two of the four gaps from the Stripe review above are now closed:
  signing-secret rotation and dispute handling.**

  `VerifyStripeWebhookSignature` accepts `STRIPE_WEBHOOK_SECRET` plus an
  optional `STRIPE_WEBHOOK_SECRET_PREVIOUS`. During a roll Stripe keeps the
  old secret valid for up to 24 hours and signs each event with *every*
  active secret; a single-secret implementation rejects events signed only
  with the new one, silently dropping real payments for a day. Verified by
  reverting to a single secret: only the mid-roll case failed, while
  "reject an unknown secret" and "retire a removed secret" stayed green —
  each test targets its own behaviour.

  New `PaymentStatus::Disputed`, via an append-only migration altering all
  three enum columns that carry a `PaymentStatus` (`payments.status` and
  both `payment_events` status columns — missing the second would fail the
  event insert and roll back every dispute silently).
  `charge.dispute.created` maps to it, reachable only from `Paid`/
  `PartiallyRefunded` and not terminal: a dispute won returns to `Paid`, one
  lost ends at `Refunded`. Two things the tests caught: the amount guard
  must not apply to a dispute — Stripe documents a dispute's amount as
  "usually the amount of the charge, but it can differ" — and
  `Dispute::$payment_intent` can arrive as an *expanded object* rather than
  a string id, which would otherwise have silently dropped a real dispute.

  `docker/nginx/stripe-ip-allowlist.conf.example` covers the third gap, IP
  allowlisting, and is deliberately **not enabled**: `stripe listen`
  forwards events from the developer's own machine, so an allowlist would
  reject every locally forwarded event and look exactly like a signature
  failure.

  Three new docs: `explanation/stripe-payments.md` (how the integration
  works end to end), `reference/testing/stripe-testing.md` (what is tested, what
  is not, and why), and `how-to/set-up-stripe.md` (MCP install through
  keys, the CLI, and how to extend it) — plus
  `explanation/secrets-and-env.md`, the standing policy on `.env` and why
  an agent reads `.env.example`, never `.env`.

### Fixed

- **Two IDOR / broken-access-control bugs found by a security pass, both
  fixed and regression-tested.** Both were the same shape — a client-writable
  Livewire public property that a query downstream trusted — and both were
  confirmed by live exploitation against the running app before being fixed.
  `reference/testing/security-testing.md` (new) is the full report, in bug-bounty
  format, with the role-access matrix and what held.

  - **SEC-001 (High): draft and embargoed articles were publicly readable.**
    `ArticleDetails::mount()` checked `->visible()`, but the `article()`
    computed property that feeds the page did not — and `articleId`, a public
    property Livewire re-hydrates from the client on every update, could be
    set to a draft's id after mount. Fixed by re-applying `->visible()` in
    `article()` (the load-bearing guard: a non-public id now 404s) and
    `#[Locked]` on `$articleId` (defence in depth).

  - **SEC-002 (Medium): a guest could read another customer's order serial.**
    `CheckoutPage::order()` did a bare, unscoped `Order::find()` on a
    client-writable `orderId`, printing `serial_number` on the payment step —
    the sequential-number enumeration `OrderConfirmation` was built to
    prevent, on a second path. Fixed by scoping `order()` to
    owner-or-session-claim (matching `OrderConfirmation`) and `#[Locked]` on
    `$orderId` and `$clientSecret`.

  Both fixes are proven by tests that go red when the fix is removed —
  `ArticleDetailsVisibilityTest` (5 cases) and four cases added to
  `CheckoutTest` — verified by the removal method this project requires. No
  `#[Locked]` existed anywhere in the codebase before this; the standing rule
  now is that any public Livewire property holding an identifier or feeding
  an authorization decision needs `#[Locked]` and a re-check at point of use.

- **Response security headers, closing an OWASP ZAP finding.**
  `SetSecurityHeaders` sets `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, a `Content-Security-Policy` and
  `Cross-Origin-Resource-Policy` on every response. Registered globally
  rather than on the `web` group, because `AdminPanelProvider` builds its own
  middleware stack and does not inherit `web` — a `web`-scoped middleware
  would have left `/admin` unheadered, and the test proving it goes red if
  the registration is narrowed.

  The CSP is deliberately not a strict one, for a measured reason: Alpine
  evaluates its attribute expressions at runtime (needs `unsafe-eval`) and
  the pages carry 176 inline `style` attributes (needs `unsafe-inline`), so a
  strict policy would stop the application working rather than harden it.
  What it does enforce is `frame-ancestors 'none'`, `object-src 'none'`,
  `base-uri 'self'` and `form-action 'self'` — the four that hold regardless
  of a permissive `script-src`, and the four the test pins. See
  `reference/testing/security-testing.md` SEC-004 and SEC-006.

  Dev-only, and recorded as such: `server_tokens off` and `expose_php = Off`
  suppress the `Server` and `X-Powered-By` version banners in the Docker
  stack. Production runs on Forge, which provisions its own nginx and PHP, so
  both still need setting there.

- **Authenticated ZAP scan reached full coverage after fixing a concurrency
  race, not a config typo.** A `threadPerHost: 2` re-run (SEC-007) still
  showed the same admin URL returning both `200` and `302` seconds apart —
  cross-referenced against nginx's own access log, not just ZAP's exit code.
  Root cause: `AuthenticateSession` re-validates session state on every
  request, so any scan concurrency above 1 can race it regardless of which
  parameter carries the setting or whether ZAP silently accepted it. Fixed
  by making both scan phases fully serial via two *global* `-config` flags —
  `spider.thread=1` and `scanner.threadPerHost=1` — rather than job
  parameters; the spider phase in particular has no job-level equivalent at
  all, and silently drops an unrecognised one without failing the run.

  Result: **469 endpoints, 0 High, 4 Medium, 4 Low, 2 Informational** — the
  highest-coverage authenticated pass to date (vs. 376 previously), with the
  race confirmed closed by re-checking the full access log for interleaved
  same-URL status codes rather than trusting the summary table. See
  `reference/testing/security-testing.md`'s "third run" note under SEC-007, and
  `reference/testing/security/zap-auth.yaml` for the corrected launch command and
  the general trap write-up in
  `~/.claude/skills/website-testing/references/security-tooling.md`.

### Known gaps

- **SEC-012: Stripe client secrets are written to the nginx access log.**
  Stripe's `return_url` redirect carries
  `?payment_intent_client_secret=…`, and nginx logs the full request line —
  so every completed card payment writes its secret to the log in plaintext.
  Already present in this environment before the check was run.

  Confirmed exploitable: a leaked secret plus the *publishable* key (public
  by design) retrieves the PaymentIntent from a browser with no
  authentication. Bounded to Low–Medium by two facts established rather than
  assumed — `CreateStripeIntent` puts **no PII on the intent** (amount,
  currency and internal ids only, no `shipping`, no `receipt_email`), and a
  `succeeded` intent cannot be re-confirmed, so no charge or refund is
  reachable. `Referrer-Policy: strict-origin-when-cross-origin` also keeps
  it out of third-party `Referer` headers, which was checked.

  Fix is to strip the query string at the confirmation route — the page
  reads none of those parameters, resolving everything from `{order}` and
  the session claim, and deliberately reporting the *webhook's* status
  rather than `redirect_status`. That also clears it from browser history,
  which an nginx log-format change alone would not.

- **SEC-013: `SESSION_SECURE_COOKIE` is unset and absent from
  `.env.example`.** It resolves to `null` and the session cookie ships with
  no `Secure` attribute. Correct locally — a Secure cookie is not sent over
  `http://localhost` — but nothing prompts a deployer to set it, so the same
  `null` reaches production, where the session cookie may travel over plain
  HTTP. `httponly` and `samesite=lax` are already correct, and
  `XSRF-TOKEN` being readable by JS is correct by design.

- **SEC-009: the CSP blocks Stripe.js, so card checkout cannot complete.**
  `SetSecurityHeaders` names no Stripe origin in `script-src`, no
  `api.stripe.com` in `connect-src`, and has no `frame-src` at all (so
  `default-src 'self'` blocks the 3-D Secure iframe). `Permissions-Policy:
  payment=()` additionally disables the Payment Request API. The production
  branch is stricter, not looser, so this breaks production too.

  Confirmed live: loading `https://js.stripe.com/v3/` produces a CSP
  violation naming the directive verbatim, `window.Stripe` stays undefined,
  and a fetch to `api.stripe.com` fails. The checkout genuinely loads that
  script (`checkout-page.blade.php:39`).

  **Why nothing caught it**: every payment test fakes `StripeClient`
  server-side, while the CSP is a response header the *browser* enforces —
  neither half can observe the other. The header test pins the four
  directives SEC-006 chose and never asserts that a script is loadable. The
  fix is four named origins, not a wildcard; recorded in
  `reference/testing/security-testing.md` rather than applied, because changing a
  security header deserves a deliberate review.

- **SEC-010: four public forms have no rate limit.** `ContactForm`,
  `NewsletterSignup`, `Register` and `ChangePassword`. Measured: 12 of 12
  newsletter signups and 8 of 8 contact messages accepted back to back.
  Bounded by a fact worth stating — **neither form sends mail** (Mailpit
  stayed at 0), so this is database flooding and moderation noise, not mail
  amplification. `ChangePassword` is the one with a security edge: it takes
  `current_password`, so unthrottled it is a guessing oracle against an
  already-authenticated session. The pattern exists twice already
  (`Login`, `TrackOrder`); what needs deciding is the key per form.

- **Filament's delete actions turn a foreseeable click into an uncaught
  `QueryException`, on two separate call sites.**

  **Single deletes:** `EditAttribute`, `EditBrand`, `EditArticleCategory`,
  `EditCarrier` and `EditCoupon` expose a bare `DeleteAction::make()` on a
  table other rows point at; MySQL refuses with error 1451 and the panel
  shows an Ignition page (`APP_DEBUG=true`) or a bare 500 in production,
  rather than a message naming what blocked it. All five confirmed live in
  rolled-back transactions — `EditCarrier` and `EditCoupon` each needed
  their blocking child row created first, since neither a shipment nor a
  redemption is seeded, so both are proven rather than inferred.

  **Bulk deletes, which is the worse half:** 13 resources expose a bare
  `DeleteBulkAction::make()`, and it calls `$record->delete()` per record —
  bypassing the Action the *single* delete was deliberately routed through.
  `ProductCategories` proves it: `EditProductCategory` correctly routes to
  `DeleteProductCategory` (which locks, counts dependants, and throws a
  domain exception naming the blocker), while the same category deleted in
  bulk reaches the raw foreign key instead. `Products` is a third shape —
  it deletes *cleanly* under the bulk path because `Product` soft-deletes,
  so `DeleteProduct`'s refusals are skipped with no error at all, which is
  harder to notice than a 500. **Routing a single delete through an Action
  does not protect the resource**; the bulk action is an independent call
  site Filament wires up by default.

  **Not a missing decision — an applied one that stopped half-way.**
  `EditProduct` and `EditProductCategory` already route their delete through
  an Action wrapped in `ReportsDomainFailures`, and the latter's docblock
  names this exact failure verbatim. The pattern was applied to 2 of the 6
  resources that need it.

  Deliberately **not** fixed by widening `ReportsDomainFailures` to catch
  `QueryException`: the trait refuses to, on purpose, and says why — *"a
  `QueryException` or a `TypeError` is a defect rather than a refusal, and
  swallowing those into a toast would hide exactly the failures that should
  be loud."* That reasoning is right. The fix is the existing one — check the
  dependency in an Action and throw a domain exception naming it, so the
  trait has something it is willing to catch — which is four vertical slices
  rather than a patch. Severity is low: staff-only, the delete is correctly
  refused, nothing is lost or corrupted; what is wrong is the failure shape.
  `reference/testing/ui-testing/phase-4-admin-panel-clickthrough.md` has the table.

- **SEC-008: a role granted `update_role` can grant itself everything.**
  Latent, not exploitable on `main` — no role holds it, and the escalation
  needs an administrator to tick the box first. But `role` sits in
  `PermissionCatalogue::CRUD_RESOURCES`, so `update_role` renders as an
  ordinary checkbox beside `update_brand`, and `RolePolicy::update()` has no
  relationship between the actor and the role being edited. Confirmed by live
  exploitation in a rolled-back transaction: granting `content_editor` that
  permission let it reach `delete_user`, which §3.3 denies it by name.

  The same bug class `UserPolicy` already closed once by splitting out
  `assignRole_user` and putting the self-edit refusal in
  `EditUser::mutateFormDataBeforeSave()` — where `Gate::before` cannot make
  it dead code. `RoleResource` never got the equivalent guard. Fix proposed
  and not applied: it changes what an administrator may do to their own role,
  which is a decision about who can lock themselves out, not only a security
  patch. Full write-up in `reference/testing/security-testing.md`.

- From the same review: **signing-secret rotation** and **dispute
  handling** are now closed — see the follow-up entry above. **IP
  allowlisting** stays open, deliberately, and is scoped as an ops decision
  rather than code: it belongs in nginx/firewall config, not Laravel, and
  `docker/nginx/stripe-ip-allowlist.conf.example` documents it without
  enabling it, since `stripe listen` would otherwise be locked out in every
  local environment.

- **Stripe calls still run inside the checkout transaction.** Deliberate —
  an intent created against an order that then rolls back is a charge for an
  order that does not exist — but stripe-php's default read timeout is 80
  seconds, so a Stripe stall holds a `payments` row lock for that long. An
  explicit short timeout is the cheap mitigation; queueing the webhook body
  behind a fast 200 is the fuller one.

- **Checkout, closing §37 criteria 6, 7 and 8 — the cycle now runs end to
  end: cart → checkout → order → payment → intent → confirmation.**

  `CheckoutPage` is one flow for guests (#6) and signed-in customers (#7);
  the only difference is prefilled details and whether the order carries
  `user_id`. `CreateOrder` → `RecordPayment` → `CreateStripeIntent` run
  inside one transaction, because a half-finished checkout — an order
  holding stock with no payment row — is the worst outcome available. COD
  skips Stripe and goes straight to confirmation.

  **The total is never submitted (#8).** The component has no price property
  at all, and Livewire refuses to bind one that does not exist, so an
  injected `total` never reaches server state — a stronger guarantee than
  validating one away. `CreateOrder` recomputes from the cart's own rows
  regardless. Asserted both ways in `CheckoutTest`.

  `OrderConfirmation` deliberately is not `Order::findOrFail($id)`. Serial
  numbers are sequential, so a bare lookup would let anyone walk
  `/checkout/confirmation/1,2,3…` and read every customer's name, address
  and order contents. You may see an order there only if you own it or just
  placed it in this session, and the refusal is a 404 rather than a 403 —
  a 403 confirms the order exists, which is the fact an enumerating caller
  is after.

  The cart's checkout button, which read "Checkout is not built yet", now
  links to it.

- **The webhook refuses to mark a payment paid for the wrong amount.**
  `payment_intent.succeeded` says a charge succeeded; it does not say it
  succeeded for what the order costs. A partial capture or an intent
  created against a different figure would otherwise mark the order Paid
  and ship goods for less than their price. Compared in minor units so the
  check is integer-exact, and recorded-but-not-applied rather than thrown,
  like every other refusal there.

- **More refund coverage**, as edge cases and as races:
  a partial refund taking exactly the remainder becomes `Refunded` rather
  than `PartiallyRefunded`; successive partials accumulate; the Stripe
  idempotency key differs between two genuine refunds of the same size, so
  the second is not silently swallowed; zero, negative, and
  already-fully-refunded are refused; and **the webhook echoing an
  admin-initiated refund does not double-count** — the delta comes out zero
  and the event records without moving anything.

  Two new races in `StripeWebhookConcurrencyTest`: a panel refund against a
  webhook refund for the same money (must total 60, not 120), and two panel
  refunds that each fit alone but together overshoot (exactly one survives).



- **Stripe payments: intent creation, the webhook, and refunds — §37
  criteria 9, 10, and 11.** The schema had been drawn for this from the
  start (`payments.stripe_payment_intent_id` UNIQUE,
  `payment_events.stripe_event_id` UNIQUE) and the SDK had been installed
  and unused since ADR-0001; this is the code that uses it.

  - `CreateStripeIntent` opens the PaymentIntent, with the amount read off
    the payment row — which `RecordPayment` copied from the order, which
    `CreateOrder` recalculated server-side, so the figure sent to Stripe
    never traces to anything a browser submitted. Idempotent twice over: a
    row lock, and a Stripe `idempotency_key` keyed on the payment, because
    the failure mode is a double charge.
  - `HandleStripeWebhookEvent` applies one event. Idempotency is the UNIQUE
    index plus a caught violation, never `exists()` — CLAUDE.md's rule, and
    this is the case it was written for.
  - `RefundPayment`, full and partial, gated by `refund_payment`.
  - `POST /stripe/webhook`, registered with **no middleware group at all**
    — no session, no cookies, no CSRF token — behind
    `VerifyStripeWebhookSignature`. CSRF-exempt by construction rather than
    by opt-out, which is what makes the exemption hard to undo by accident.
  - `PaymentResource` at `admin/payments`: read-only apart from Refund,
    with a `payment_events` relation manager as the reconciliation surface.
  - `Money::toMinorUnits()` — Stripe takes integer cents, and CLAUDE.md
    routes every money operation through `Money` rather than leaving a
    `bcmul` with a hand-written scale at a call site.

  **Two bugs the tests caught, both in code written this session:**

  Stripe reports `amount_refunded` as a *cumulative* total, while
  `TransitionPaymentStatus` *accumulates* what it is given. Passing Stripe's
  figure straight through double-counted every refund after the first —
  refund 25 then 40 and the row read 65 instead of 40. The webhook now
  computes the delta.

  And the early return for "target status equals current status" silently
  dropped every partial refund after the first, because two successive
  partial refunds leave the status unchanged while the amount moves.
  `PaymentStatus`'s matrix says so out loud by listing `PartiallyRefunded`
  as reachable from itself; the guard contradicted the enum's own design.

  A third, smaller finding from the concurrency test: an event that applied
  nothing was recorded with `note = null`, indistinguishable from one that
  applied the transition. For a table read to reconcile a payment that is
  misleading, so no-ops now say why.

- **`tests/Feature/Payment/StripeWebhookSecurityTest.php` — an adversarial
  suite against the webhook**, written from the attacker's side rather than
  the happy path. Eleven cases: unsigned, wrong secret, malformed header,
  body edited after signing, a signature captured for one intent replayed
  against another, expired timestamp, unconfigured secret, and whether the
  error responses leak which PaymentIntent ids exist.

  Validated by replacing the middleware with a deliberately vulnerable
  version that trusts the body: **eight of eleven failed**, including
  "refuses an unsigned request claiming a payment succeeded". The three that
  still passed are correctly scoped — idempotency rests on the UNIQUE index
  rather than the signature, and route shape is unaffected.

- **`tests/Concurrency/StripeWebhookConcurrencyTest.php`** — two real
  processes delivering the same event at once, which a single-process test
  cannot express. Removing each guard in turn established which half does
  what: the UNIQUE index prevents the double-apply, while the caught
  violation is what makes the loser a graceful 200 instead of a 500 Stripe
  would retry for days. `explanation/security-model.md` records the full
  finding, including that a check-then-act version still passed *because*
  the index backstops it.

- Merged `origin/cart-page` (cart page, cart badge, header integration).

### Known gaps

- **`Money::percentageOf()` truncates rather than rounds.** Found while
  asserting VAT through checkout: 20% of a 100.00 gross line is 16.6667,
  and the method returns `16.66` because its double-scale intermediate is
  narrowed with `bcadd`, which truncates. Every VAT figure in the
  application is therefore up to a cent low, systematically in the same
  direction. Left alone deliberately — it is shared money code used by
  every total, changing it moves existing figures, and it is a decision
  about rounding policy rather than part of the Stripe work.
  `CheckoutTest` asserts the current value and says why.

- **~~The Stripe integration has not been run against the real Stripe test
  API.~~ Closed 2026-09-04.** Credentials are now populated and the whole
  cycle has been driven end to end against sandbox account
  `acct_1U9BTmEinvfvnBsb`, including a **real 3-D Secure challenge**: intent
  created (`requires_payment_method`, 1990 eur), confirmed with
  `tok_threeDSecure2Required` (`requires_action`), the hosted 3DS2 page
  completed in a real browser (`succeeded`, `amount_received: 1990`), and
  the resulting `payment_intent.succeeded` accepted by the webhook — moving
  the payment `pending` → `paid`. Redelivering the same event stayed at one
  `payment_events` row; the same payload unsigned returned 400.
  `reference/testing/stripe-testing.md` has the full table.

  The tests still fake `StripeClient`, deliberately — a suite that reaches
  the network depends on credentials and connectivity. What has changed is
  that the request shapes those fakes assert are now known to be the ones
  Stripe actually accepts.

- **One flaky test.** A full run showed `1 failed, 977 passed`; the
  immediately following run showed `978 passed`, and a repeat of the whole
  Concurrency suite passed 48/48. Not identified. Most likely one of the
  timing-barrier concurrency tests, which `RaceHelper` already documents as
  sensitive to a loaded machine (`RACE_BARRIER_SECONDS` exists for this).

- **`ShipmentResource`, closing §37 criterion 15 ("a shipment can be created
  from an order").** `CreateShipment` and `TransitionShipmentStatus` were
  built and tested with no panel surface at all — `warehouse_employee` held
  `create_shipment`/`update_shipment` and had nothing to reach either
  through, the same shape the inventory gap had.

  `admin/shipments` for the list, view, tracking history, and a **Change
  status** menu generated from `ShipmentStatus`'s matrix. Creation is an
  action on the *order's* page rather than a form here, because §28 refuses
  a shipment for a cancelled, unpaid, or already-shipped order — a
  standalone create form would invite picking an order `CreateShipment` then
  refuses. Its refusals surface as notifications via `ReportsDomainFailures`
  rather than 500s.

- **`UserResource`, and a new `assignRole_user` ability.** `UserPolicy`'s
  docblock had named this and deferred it: role assignment through a user
  form would be gated by `update_user`, so anyone who could edit a user
  could promote themselves to administrator. Splitting it out is what makes
  that impossible rather than merely unlikely — the permission catalogue
  goes from 106 to 107.

  `admin/users` lists accounts and the roles they hold, with edit for
  profile fields, `is_active`, and roles. No create page: an account exists
  because someone registered (§7), and adding one here would mean a second
  password surface to audit. No delete: removing an account orphans its
  orders and §19 requires that history to survive — `is_active` is the
  reversible path, and both `canAccessPanel()` and `EnsureAccountIsActive`
  honour it on the next request.

  **The `Gate::before` trap this surfaced.** The "you may not change your
  own roles" half was first written into `UserPolicy::assignRole()` as
  `&& $model->id !== $user->id` — dead code, because `Gate::before`
  short-circuits every check for an administrator, the only role holding
  `assignRole_user`. The test caught it; the rule now lives in
  `EditUser::mutateFormDataBeforeSave()`, where `Gate::before` cannot reach.
  This is the ADR-0006 consequence `tech-stack-overview.md` has always
  described, hit in practice for the first time.
  `permissions.md`'s "Where each check lives" now states the general form.

  A second thing the tests caught: the first draft of the two denial cases
  used `fillForm()->call('save')` and **passed with the server-side guard
  deleted** — the form disables the roles field for exactly those actors, so
  the submission never carried `roles` at all. They now call
  `mutateFormDataBeforeSave` directly, which is the seam a crafted Livewire
  payload actually reaches. A disabled control is not security.

  Also fixed while building the form: `TextInput::tel()` on the phone field
  rejected the seeded `+1-667-538-7880` via `telRegex()`'s default pattern.
  The column is a plain `string(30)` and neither the storefront nor the
  schema imposes a format, so the regex is cleared and length is the only
  constraint asserted.

- **Order status is now changeable from the panel, closing §37 criterion
  16.** Everything underneath had been built and tested —
  `TransitionOrderStatus` (the only writer of `orders.status`),
  `OrderPolicy::updateStatus()`, ADR-0004's transition matrix, three
  concurrency tests — and nothing in Filament called any of it. The one
  button `ViewOrder` did carry was an `EditAction` pointing at a route
  `OrderResource::getPages()` never registers, so it rendered and could not
  resolve.

  A **Change status** menu on both the table row and the view page,
  generated from `OrderStatus::allowedTransitions()` rather than
  hand-written, so the matrix stays the single place that rule lives and
  widening the enum widens the menu. Same shape `ArticlesTable` already used
  for `ArticleStatus` — one pattern for status menus in this panel, not two.

  It differs from the article menu in one way that matters: authorization is
  per *target* status, not one fixed ability for the whole group, because
  `OrderPolicy::updateStatus()` routes by target. ADR-0011 makes cancelling
  and refunding administrator moves distinct from a warehouse employee's
  routine advance, so the same order shows the two roles different buttons.
  A reason field on the modal feeds the §19 history row.

  `tests/Feature/Filament/OrderStatusActionTest.php` — 6 cases, confirmed to
  fail with the change reverted before being restored (5 failed on the
  reverted `ViewOrder`; the 6th passed, because it exercises the table,
  which was not reverted — the split is what proves each case targets what
  it claims).

- **New `InventoryResource` closes a permission-vs-panel-access gap for
  `warehouse_employee`.** Audited what the Filament panel actually offers
  against the permission catalogue and each of the three roles: the role
  holds `update_inventory`, but the only existing UI for `AdjustStock` was
  `ProductVariationsRelationManager`, nested under `ProductResource` and
  gated by `viewAny_product` — a permission this role's scope (§3.4)
  deliberately excludes ("Not products, not articles, not payments, not
  users", `permissions.md`). Confirmed directly rather than assumed: a raw
  authenticated request to the old path returned 403 for the role that
  supposedly held the ability to adjust stock.

  `admin/inventories` — index (searchable by product/SKU, sortable, with
  low-stock/out-of-stock filters, same colour-coded available-stock badge
  `ProductVariationsRelationManager` already used) and a view page with
  `adjustStock`/`recordDamage` header actions, both calling the existing
  `AdjustStock`/`RecordDamage` Actions rather than new ones — no new
  business logic, only a second reachable surface for what already existed.
  A `InventoryMovementsRelationManager` shows the ledger, same read-only
  shape as `OrderStatusHistoriesRelationManager`. No create or edit page:
  `InventoryPolicy`'s own docblock already states why — a row exists
  because a variation exists, and stock changes through a recorded
  movement, never a direct quantity write.

  Verified against the running panel with real authenticated requests per
  role (200 for `warehouse_employee` and `administrator`, 403 for
  `content_editor`), not only by reading the policy.
  `tests/Feature/Filament/InventoryResourceTest.php` — 6 cases, each
  confirmed to fail without the resource in place before being restored.

  Same audit found `shipment` in the identical shape — permission granted
  to `warehouse_employee`, `CreateShipment`/`TransitionShipmentStatus` built
  and tested, no Filament resource at all — and order status (§37 #16) still
  has no panel action, `ViewOrder`'s `EditAction` pointing at a route
  `OrderResource::getPages()` never registers. Neither addressed here;
  `permissions.md`'s `shipment` row and `misc/todo.md`'s P1 have the detail.

- **`Auth::logoutOtherDevices()` on the storefront now actually invalidates
  other sessions.** `ChangePassword` called it and its docblock stated the
  guarantee, but the call is inert unless
  `Illuminate\Session\Middleware\AuthenticateSession` is in the stack —
  and it was in Filament's panel stack (scaffolded by the installer) and
  not in the storefront's `web` group. So a password change, the standard
  response to "someone else may be signed in as me", left every other
  browser signed in for customers while working correctly for staff.
  Registered on the `web` group. `troubleshooting.md` has the full symptom,
  including why a grep for the class finds a hit that does not apply.

- **A customer deactivated or soft-deleted mid-session is now signed out on
  their next request**, via new `App\Http\Middleware\EnsureAccountIsActive`.
  `Login` checks `is_active` as part of the credentials, but that is one
  instant; nothing re-checked it afterwards, so deactivating a customer did
  nothing until their session happened to expire. `canAccessPanel()` already
  made exactly this argument for the panel ("a session outlives the row it
  authenticated against") — this is the same rule for the storefront, which
  had no equivalent. Soft-deletion counts as inactive and is checked
  explicitly rather than left to the guard's global-scope behaviour.

- **Losing the registration email race now shows a form error instead of a
  500.** `Register`'s `unique` rule and the `users.email` index are two
  halves of one check: between validating and inserting, another
  registration can take the address, and the index — not the rule — is what
  decides. The `UniqueConstraintViolationException` is now caught and
  converted to the same message the ordinary duplicate produces, so the two
  are indistinguishable to the user. Catch-and-convert rather than
  check-then-act, the rule `CLAUDE.md` already states for idempotency.

- `tests/Feature/Livewire/AuthSessionInvalidationTest.php` — ten cases
  covering the three above. Not in `tests/Concurrency`: there is no
  contested row and no lock picking a winner, which is what that harness is
  for; what was missing was enforcement on a later request. Each case was
  re-run with its fix reverted to confirm it fails — which caught a first
  draft of the duplicate-email test that passed with the `catch` deleted,
  because it planted the competing row before `->call('register')` and so
  was rejected by `validate()` re-querying the rule rather than by the
  index. It now plants on Eloquent's `creating` event. Runs on CI shard 2,
  which lists `tests/Feature/Livewire` as a directory.

  The four cases that hit a real route (`->get('/catalogue')`, needed
  because `EnsureAccountIsActive`/`AuthenticateSession` are HTTP
  middleware that `Livewire::test()` never exercises) passed locally and
  failed in CI with `ViteManifestNotFoundException` — `public/hot` from
  the always-running local `vite` container hides the manifest path that
  CI, with neither a dev server nor a build step, actually hits. Fixed by
  faking a minimal manifest in `beforeEach`, cleaned up afterwards.
  `troubleshooting.md` has the full mechanism.

- The login page renders `session('status')`, which nothing did before —
  `EnsureAccountIsActive`'s explanation of why the session ended would
  otherwise have been set and silently discarded.

- `docs/reference/testing/ui-tests.md` — every storefront and admin-panel UI test
  (`tests/Feature/Livewire/*`, `tests/Feature/Filament/*`), grouped by
  component or resource, stating what each one actually proves and, where a
  test exists because of a real incident, what that incident was. Not a
  replacement for the write-rules pages, which state the contract — this
  page states which test proves which piece of it, so a behaviour change
  has a known list of tests to check rather than a full-suite guess.

- **Catalogue search now matches an attribute value's own text ("Linen",
  "Red"), not only a product's name and blurb.** A shopper typing a
  material or colour they remember has no reason to know whether that fact
  lives on the product itself (descriptive) or on one of its variations (an
  axis), so `ProductList::applyFilters()`'s search clause checks both
  pivots — the same "either pivot answers it" rule the attribute-value
  filter and `attributeFacets()` already use. Deliberately not a
  concatenated "searchable text" blob column or method: that needs building
  and keeping a denormalised value in sync on every write for a `LIKE` that
  still cannot use an index either way, for the cost of three explicit
  `LIKE`s over columns this schema already reads.

- **A variation's stock can now be adjusted after creation, and is visible
  on the panel at all.** Reported live: `initial_quantity` on
  `AddProductVariation` was the only way stock ever entered the system, and
  it is create-only, so once a variation existed nothing in the panel could
  show or change what it held. New `App\Actions\Inventory\AdjustStock`
  records a signed delta as either `NewDelivery` or `ManualCorrection` — the
  two `InventoryMovementType` cases the schema had carried unwritten since
  it was drawn — and refuses a reduction that would push stock below what an
  order already has reserved, the same guard `RecordDamage` uses. The
  variations table gained a Stock column (available, colour-coded, on-hand
  and reserved in the tooltip) and an "Adjust stock" row action.

- **Descriptive attribute values are now shown on the storefront product
  page**, as chips grouped by attribute and linked into the catalogue filter
  for that value — rendered rather than left invisible now that the
  filtering side existed but nothing displayed what a product had been given.

- **Multiple product images can now be uploaded in one action**, alongside
  the existing single-image form (which stays, for setting alt text — an
  accessibility requirement the bulk path skips). Composes
  `AddProductImage` once per file, so the first-image-becomes-main rule and
  ordering hold exactly as for a single upload. The bulk field's own
  dimensions check is a closure rather than the built-in `Dimensions` rule,
  which — on a `->multiple()` field — validates every file through one
  nested validator and reports only the first failure with no filename
  attached; the closure names the specific undersized file instead.

- **A catalogue card now represents a buyable variation, preferring the
  deepest discount, rather than always the product's own sticker price.**
  Reported live: a product's default variation being out of stock left the
  card showing "Out of stock" and the product's own regular price, even when
  a buyable, discounted sibling sat one click away.

  New `App\Support\ResolveCardVariation` — same shape as
  `ResolveVariationImage`/`ResolveVariationPrice`, resolved at read time,
  nothing stored: the default variation wins when it is itself buyable
  (`is_available` and `available() > 0`, the same guard `availableStock()`
  uses); otherwise the buyable variation with the largest discount
  percentage wins, using the same `percent` a card's own badge already
  computes, so the two can never disagree. Falls back to the default (or
  first) variation when nothing is buyable at all, so "Out of stock" still
  has something to attach to. `ProductList::price()` now goes through it
  instead of `ResolveProductPrice::current()` unconditionally.

- **Filtering the catalogue by more than one value of the same attribute now
  ORs them, rather than requiring a variation to be all of them at once.**
  Reported live: checking Colour=Black and Colour=White together returned
  zero products, because every selected value was AND-ed against every
  other regardless of which attribute it belonged to — no variation can ever
  be two colours. The rule is now OR *within* one attribute's checked
  values, AND *across* attributes: Black or White, but still narrowed
  against a separately-checked Material.

  The sidebar's checkbox list is now one `<select multiple>` dropdown per
  attribute. Livewire cannot bind several independent multi-selects to one
  shared array without each overwriting the others' picks on change, so each
  dropdown targets its own key in a new, non-`#[Url]` `$facetSelections`
  property; `ProductList::updated()` folds every change back into the
  `$attributeValueIds` list the query and the URL actually read.

  Also closed: a variation-only attribute (Colour, Size — anything a
  customer chooses between rather than a product asserts about itself) had
  no way to reach the sidebar or the filter at all, since it can never be a
  product's own descriptive value. A value now counts if the product carries
  it descriptively **or** if any of the product's own variations carries it
  as an axis — the storefront no longer needs to know which pivot answers a
  given filter.

- **The category `<select>` dropdown showed "All categories" regardless of
  which one was actually selected**, on first paint and after any
  server round-trip — the chip and the filtered results were correct
  throughout, only the dropdown's own display lagged, because Livewire's
  client-side morph sets a `<select>`'s value after its own JS attaches, and
  the server-rendered HTML carried no `selected` attribute on any `<option>`
  for it to fall back to. Now rendered explicitly with `@selected(...)`.

- **Products can now carry descriptive attribute values — a fabric
  composition, a set of scent notes, a certification — and the catalogue
  can be filtered by them.** New pivot `attribute_value_product`
  (`2026_08_30_110000_create_attribute_value_product_table`), owned by
  `SetProductAttributeValues`.

  This is the half the variation grid could not express. **Multiple values
  of one attribute are legal here and illegal on a variation**, which is
  the entire reason it is a second pivot rather than more rows in the
  first: "50% cotton, 50% polyester" is one fact about one sellable thing,
  and two values on one axis makes "which variation is Material=cotton?"
  ambiguous, breaking the combination uniqueness the grid depends on.
  Modelling a blend as two variations would invent two SKUs that do not
  exist. The dividing question is whether the customer *chooses* between
  the values — a multicoloured jacket is one variation whose colourway is
  named "Floral Print", with the constituent colours descriptive.

  An attribute cannot be both for one product:
  `AttributeValueIsAVariationAxisException` refuses a descriptive value
  whose attribute is already a variation axis, because a filter reading
  both pivots would otherwise return the product for a colour no variation
  actually has. The `attribute_product_category` allow-list applies here
  too — a perfume has no business carrying a shoe size descriptively
  either.

  Chosen over free-text `product_specifications`, which stays the right
  home for prose nobody filters on but cannot back a filter: nothing
  constrains its `value`, so "Cotton", "cotton", and "100% Cotton" are
  three values no query can group. `attributes.is_filterable` — a flag
  unread since the schema was generated — is what gates the new facets.

  Storefront: `?attributeValueIds[]=` on the catalogue, **AND**ed across
  selections (Cotton + Organic means both, not either), with facets counted
  against every other filter so none promises results that are not there.
  The ids are re-resolved against real, filterable values on every query
  rather than trusted from the URL, and the property is `mixed`-typed and
  sanitised — the same `#[Url]`-hydration incident class as
  `ProductDetails::$variationId`, with hostile-input tests to match.

  The facets are **category-scoped**, and only appear once a category is
  picked — before that the sidebar shows the generic filters alone, since
  Colour and Size mean nothing across a catalogue that also holds power
  tools and moisturiser. Scoping is inherited: an attribute allowed for a
  master category is offered on every descendant without being re-scoped at
  each depth, so Material attached once to "Clothing" reaches "Clothing >
  Men > Tops" and every leaf under it, while a sibling branch such as
  "Beauty & Personal Care" is unaffected. Verified against the real seeded
  4-level tree, not only in fixtures. Each selected value gets its own
  dismissible chip that drops that value alone, since the values are AND-ed
  and widening by one step is the point.

  Resolving the selected category is now one memoised `#[Computed]` lookup
  shared by the facet list, the filter chip, and `applyFilters()`, replacing
  three separate `where('slug', …)` queries per request.

- **Seed fixtures carry both new pivots.** The vocabulary file
  (`reference/catalogue.json`) gained a `categories` key per attribute,
  scoping all nine to the master categories where they belong — `shoe-size`
  to `workwear` alone, `scent` to `beauty`, `power-source` to power tools
  and garden. Descendants inherit, so only the top of each branch is named.
  `CatalogueReferenceSeeder` `sync()`s it, so dropping a category from the
  list removes the row on re-seed rather than leaving it stale.

  Product documents gained an optional `attribute_values` list of dotted
  `attribute.value` slugs — the descriptive set. Thirteen demo products now
  carry material values mapped from what had been free-text specifications,
  and the survey that produced them is the argument for the whole feature:
  **27 distinct spellings** of what is really six materials ("100% cotton",
  "Cotton twill", "Cotton fleece", "Cotton-elastane rib", …), none of which
  a filter could ever have grouped. `WRK-0007` ("Ripstop polyester-cotton")
  is the blend case, now two values of one attribute on one product.

  Materials with no vocabulary entry — acrylic, silk, cashmere, viscose,
  chrome vanadium — keep their prose specification and gain no pivot row.
  Inventing vocabulary to force a match would be worse than leaving them
  unfilterable, and the specification text was never removed for any
  product: the pivot is additive.

  `fixtures:validate` gained the matching check — an unknown value slug, or
  a value whose attribute the product already varies by, now fails before
  seeding rather than halfway through a run with rows already written.
  Both failure modes were confirmed to fire, then reverted.

- **The category tree can no longer be made cyclic.** `parent_id` is a
  self-referencing foreign key and no foreign key can express acyclicity —
  the database accepted A→B→A without complaint, after which every walk of
  the relationship (the storefront filter, the admin ancestry breadcrumb,
  the mega-menu) loops forever. Two layers now: the parent dropdown
  excludes the category's own descendants, and the new
  `UpdateProductCategory` Action refuses the move outright with
  `CategoryCycleException` whatever the form offered.

  Only the edit path needs it — a category being created has no descendants,
  so no choice of parent can close a loop through it, and
  `CreateProductCategory` stays on Filament's default create. The Action
  locks the category being moved before walking its descendants: without
  it, two administrators reparenting A under B and B under A concurrently
  each see a tree in which their own move is legal, and both commit.

- **Admin tables shortened, and a category's place in the tree made
  visible.** The panel's tables had grown wide enough to scroll: Coupons
  now leads with code, value, and a single "Validity" column (the
  start/end pair only ever means anything read together, and "1 Sep –
  30 Sep" is shorter than either timestamp alone), with the rest
  toggleable. Articles truncates long titles to 40 characters with the
  full text on hover and shows `published_at` as a date; Contact messages
  show dates rather than timestamps and can have their account
  reassigned by hand (`user_id` was already nullable — a guest message,
  or one sent from a typo'd address); Orders and Product reviews keep
  customer name and email searchable but hidden by default. The panel
  layout's own internal scroll region is overridden so long tables grow
  the page instead of scrolling inside a viewport-height box.

  Clicking a product category now shows its full ancestry, root first,
  with the category itself emphasised and every ancestor a link —
  `ResolveCategoryFamily::ancestryOf()`, guarded against a `parent_id`
  cycle the schema does not prevent. Its parent picker uses the same
  indented tree the product form and storefront sidebar do, rather than a
  flat alphabetised list.

- **Attributes can now be restricted to specific product categories, so
  "Shoe Size" can no longer be picked as a variation axis for a perfume.**
  New pivot `attribute_product_category` (composite primary key,
  `2026_08_30_100000_create_attribute_product_category_table`) — an
  allow-list an admin opts an attribute into via a new "Allowed categories"
  field on `AttributeResource`'s form, not a mandatory classification: an
  attribute with no rows there is unrestricted, exactly as every attribute
  behaved before this table existed, so none of the ~40 seeded attributes
  needed retrofitting.

  A category inherits every ancestor's allow-list — "Colour" scoped to the
  master "Clothing" category is available on "Clothing > Men > Tops"
  without being re-scoped at every depth.
  `App\Support\ResolveAllowedAttributes` is the pure function that resolves
  this (self-plus-ancestors, unioned with every unrestricted attribute);
  `ResolveAllowedAttributesTest` covers exact-match, descendant inheritance,
  and the two directions inheritance deliberately does *not* flow — sideways
  to a sibling, or up to an ancestor.

  Enforced in two places, the same split as the rest of this session's
  catalogue work: `ProductForm`'s "Variation axes" field now scopes its own
  options to the product's chosen category reactively (a convenience, not
  the enforcement), and `CreateProduct`/`UpdateProduct` refuse a disallowed
  axis outright with the new `AttributeNotAllowedForCategoryException` —
  checked *after* the category itself is saved, so one request that both
  moves a product into a new category and sets an axis that category allows
  is not refused for a mismatch only ever true before the save committed.

- **A variation's attribute-value combination — "Scent: Vanilla, Volume:
  50ml" on one SKU — can now actually be set through the admin panel.**
  Before this, `ProductForm`'s "Variation axes" field let an admin pick
  which attributes a product varies by, but nothing anywhere let them say
  which value each individual variation carries — the create repeater and
  the edit-time relation manager both created bare SKU/price/stock rows
  with no way to map a variation to a point in that space at all.

  New Action, `App\Actions\Catalogue\SetVariationAttributeValues`, mirrors
  `SetVariationImages`'s shape exactly: one Action owns the whole
  combination as a set, under the same `products` lock, proven the same
  way — `SetVariationAttributeValuesConcurrencyTest` fails 4/4 with the
  lock removed. Composed by `AddProductVariation` when the caller supplies
  `attribute_value_ids`, so a variation gets its combination in the same
  call that gives it a SKU and a stock row; also callable directly, which
  is what the relation manager's edit action uses. Enforces the two rules
  ADR-0005 names as outside the database's reach: a value has to belong to
  an attribute the product actually declared as an axis
  (`AttributeValueNotOnProductException`), at most one value per attribute
  (`DuplicateVariationAttributeException`), and no two live variations of
  one product may carry the identical set
  (`DuplicateVariationCombinationException`) — a soft-deleted variation is
  excluded from that last check, so a discontinued combination can be
  reused.

  Both admin forms' pickers are grouped by attribute name and scoped to the
  product's own chosen axes, never the whole catalogue's attribute values.
  The create-time repeater does this reactively against the product's own
  "Variation axes" field in the same unsaved form, via a relative `Get`
  path out of the repeater (`../../attributes`) — confirmed against the
  installed Filament version's own path-resolution code, not assumed.

  Building this surfaced a real, separate bug: `CreateProduct` relied on
  `ProductForm`'s "Variation axes" field being a `->relationship()` Select,
  which Filament saves *after* `handleRecordCreation()` returns — too late
  for a variation's combination to validate against axes that were about
  to exist one line later in the same request. Every variation given a
  combination at create time was refused with
  `AttributeValueNotOnProductException` against a product that, moments
  later, would have had exactly those axes. Fixed by having `CreateProduct`
  and `UpdateProduct` sync `attribute_product` explicitly instead of
  relying on Filament's own timing; `ProductForm`'s field is now a plain
  `->options()` Select, and `EditProduct::mutateFormDataBeforeFill()`
  hydrates it back on open, which `->relationship()` used to do
  automatically. Reproduced red before the fix, confirmed green after.

  `docs/reference/write-rules/product-variation-attribute-values.md` is the
  new outcomes page, mirroring `product-variation-images.md`'s shape.

  While in `.github/workflows/ci.yml` for this feature's own new test
  files, found four pre-existing `tests/Concurrency` files
  (`SetVariationImagesConcurrencyTest`, `CreateShipmentConcurrencyTest`,
  `RecordPaymentConcurrencyTest`, `CreateProductReviewConcurrencyTest`)
  that had never been added to any shard and so had never run in CI at
  all, despite all ten of their tests passing locally. Added to the
  lighter shards (b and c) per `use-ci.md`'s own placement rule.

- **Admin product form's category dropdown now shows the same tree the
  storefront sidebar does** — every category indented by depth, siblings
  alphabetical, instead of a flat alphabetised dump of all 173 rows an
  admin had no way to place in the hierarchy. `ResolveCategoryFamily`
  gained `allOrderedWithDepth()`/`selectOptions()`, reusing the same
  `orderedTreeWithDepth()` walk the storefront filter already relies on
  rather than a second, separately-maintained tree builder. `ProductForm`'s
  `product_category_id` field switched from `->relationship()` to a plain
  `->options()` array, since the label's indentation lives in
  application code the relationship's own query-driven option building
  can't express; the field name is unchanged, so create and edit hydration
  are unaffected.

- The journal — `Journal\ArticleList` at `/journal` and
  `Journal\ArticleDetails` at `/journal/{article:slug}`, closing §37
  criterion 17's reader side. Filter by category and tag (both `#[Url]`),
  a lead story, a numbered index, related articles, and a reading-progress
  bar.

  Visibility is **two** conditions, not one: `status = Published` **and**
  `published_at <= now()`. An article can be marked published with a future
  date, and filtering on status alone leaks it early. Both live in
  `Article::visible()`, an Eloquent scope, so the list filter and the detail
  page's 404 cannot drift apart — the detail page asks the same scope
  whether the article is visible rather than re-deriving the rule.

- `App\Support`-style generated cover art for articles —
  `<x-journal.cover>`, a Blade component drawing layered OKLCH conic and
  radial gradients, an SVG turbulence grain and a halftone screen from
  `crc32($article->slug)`.

  Not a stylistic choice first: every one of the 23 articles carries a
  `main_image_path` pointing at a file that does not exist.
  `demo:fetch-images` covers `product_images` only, and no `PEXELS_KEY` is
  configured, so the paths render as broken images. Generating from the slug
  is the same move `App\Support\PlaceholderImage` makes for products —
  deterministic, so an article keeps its artwork across re-seeds, and there
  is no file to be missing. Real article photography is still owed, in the
  fixtures or in an extension to `demo:fetch-images`.

- `Instrument Serif` as `--font-display`, added through the existing Bunny
  pipeline in `vite.config.js` rather than a raw Google Fonts `<link>`. The
  journal needed a voice distinct from the shop, and a high-contrast serif
  is the cheapest way to read as editorial rather than as a product listing.
  Georgia is the fallback rather than a generic serif — near-universal, and
  close enough in metrics that the swap is not a visible reflow.

- `ember`, a second accent ramp in `@theme`, deliberately scoped to the
  journal. Two accents on a product grid is one too many.

- `docs/how-to/write-a-storefront-page.md` — every way UI is written here
  and what breaks each one: the four kinds of view and how to choose, the
  Livewire directives in use and when each is wrong, `#[Url]` as
  attacker-controlled input, computed-cache invalidation, the Tailwind
  source-scanning trap, the accessibility baseline already met, and the
  paint-order rule that positioned elements beat static ones regardless of
  DOM order. Written because most of those failures render a plausible page
  rather than an error.

- **Staff-only "Demo order" catalogue sort** — walks 13 real products, each
  chosen to showcase one distinguishable case (multi-image variations, an
  impossible attribute combination, a parent-category product, out of
  stock, `min_order_quantity > 1`, no images at all, …).
  `docs/reference/demo-showcase-order.md` has the full list, the reasoning
  per case, and two wrong picks caught before shipping (a placeholder SKU
  that never existed, and a product confused "deactivated" with "listed but
  out of stock" — two states this schema already distinguishes).

  Two new nullable columns, `products.demo_case_order`/`demo_case_label`
  (`2026_08_30_090000_add_demo_showcase_columns_to_products`), assigned by
  `Database\Seeders\Demo\DemoShowcaseOrderSeeder` — matched by SKU, not id,
  and resets every product's assignment before reassigning so a SKU dropped
  from the list can never leave a stale label behind.

  Gated by `ProductList::isDemoModeAvailable()` — `canAccessPanel()`,
  checked identically wherever the sort button renders, wherever the query
  applies it, and wherever the product-card badge shows the case label, so
  none of the three can disagree. Once active, the sort shows **exactly**
  the 13 curated products — every other filter (search, category, brand,
  price, rating, stock, sale) is bypassed entirely, not narrowed further,
  so a stray filter left over from browsing can never silently drop a case
  out of a live walkthrough. A guest sending `?sortBy=demo_case_order`
  directly gets no effect — the value is not in the public `SORTS`
  allow-list, so it falls back to the normal sort, verified live.

  The no-images case (`BTY-0001`) needed manufacturing, not finding — every
  product in the seeded catalogue has at least one real photo
  (`demo:fetch-images` covered all 182 rows), so this is the one case the
  seeder does more than label: it deletes the product's `product_images`
  rows outright. Surfaced a gap this closes too — every "no image"
  placeholder on the storefront (`product-list.blade.php`'s cards,
  `product-details.blade.php`'s gallery) was a generic SVG icon; both now
  show `public/images/default-product.png` instead.

  5 tests in `ProductListDemoOrderTest` cover the exact-13 restriction
  (order and exclusion together), the filter-bypass, the guest fallback,
  and the badge visibility rule (both directions — shown in demo mode,
  hidden under a normal sort and hidden entirely from a guest).

- **Input-crash testing methodology and a per-property tested-input log** —
  `docs/how-to/test-for-input-crashes.md` (the technique: DevTools, URL, or
  a Livewire component test — plus the value playbook: overflow,
  malformed-numeric, negative, XSS-shaped, SQLi-shaped, oversized string,
  value outside a fixed allow-list) and
  `docs/reference/testing/tested-inputs.md` (the running per-property index of
  what has actually been checked, where, and the result — not one row per
  component, since the lesson below is specifically that a property, not a
  component, is the right unit of coverage).

  Auth (`Login`, `Register`, `ChangePassword`) and contact
  (`ContactForm`, `NewsletterSignup`) all came back clean — every property
  on all five is `string`-typed, so the hydration-crash class does not
  apply structurally, and every `max:` validation rule matches its column
  length exactly (checked against the migrations, not assumed).

- **Admin product form's category dropdown now shows the same tree the
  storefront sidebar does** — every category indented by depth, siblings
  alphabetical, instead of a flat alphabetised dump of all 173 rows an
  admin had no way to place in the hierarchy. `ResolveCategoryFamily`
  gained `allOrderedWithDepth()`/`selectOptions()`, reusing the same
  `orderedTreeWithDepth()` walk the storefront filter already relies on
  rather than a second, separately-maintained tree builder. `ProductForm`'s
  `product_category_id` field switched from `->relationship()` to a plain
  `->options()` array, since the label's indentation lives in
  application code the relationship's own query-driven option building
  can't express; the field name is unchanged, so create and edit hydration
  are unaffected.

### Fixed

- **The Category and Brand filters were native `<select>` elements, whose
  own popup rendering — width, position, and open/close timing — belongs to
  the browser rather than to this page.** Reported live as the filter panel
  looking broken while a native select's popup was open on a narrow
  viewport. Both are now the same custom dropdown pattern as the attribute
  facets: a small trigger button showing the current choice, opening a
  plain, absolutely-positioned panel on hover or focus — single-select, so
  choosing a value closes the panel immediately, unlike a multi-value
  attribute facet. Category keeps its tree indentation, now as real
  left-padding per depth rather than a repeated "— " prefix, which was only
  ever a workaround for a native `<select>`'s inability to render anything
  but plain text per `<option>`.

- **A facet's hover panel could close before a value in it was clicked.**
  The panel sat `margin-top` below its trigger button, and since the panel
  is `absolute` and out of flow, the wrapping element's own hoverable box
  only ever covered the button — the instant the cursor crossed that
  margin gap on the way down to the panel, `mouseleave` fired and closed
  it. Changed the gap to `padding-top` on the wrapping box instead, so the
  gap is part of the same hoverable region rather than a dead zone outside
  it.

- **A facet's own count didn't narrow when a different attribute's value was
  selected, and Size read L, M, S, XL, XS instead of XS through XXL.**
  Reported live against Material=Denim (3 products): Colour and Size still
  showed their whole-catalogue counts rather than narrowing to what those 3
  Denim products actually have. `ProductList::applyFilters()`'s `$skip`
  parameter was all-or-nothing across the whole attribute-value dimension —
  computing one facet's count had to either apply every selected attribute
  or none, with no way to exclude just the one attribute being evaluated.
  New `$skipAttributeId` parameter excludes only that attribute, so a
  facet's counts narrow against every *other* selection without a value's
  own selection zeroing out its own count. The Size ordering was separate:
  `Collection::sortBy()` only accepts one sort criterion per call, and a
  bare array of closures — the multi-column form some other collection
  methods accept — silently sorted by neither, falling through to whatever
  order the query itself returned. `attributeFacets()` now groups first and
  sorts each group on `sort_order` afterward.

  The facet UI itself changed from one `<select multiple>` per attribute to
  a small hover-opened dropdown per attribute — a minimal trigger
  ("Colour ▾", with a count badge once something in it is picked) rather
  than every value shown at once, which also means Livewire no longer needs
  the `facetSelections` staging property that existed only to work around
  several independent multi-selects being unable to share one bound array;
  `toggleAttributeValue()` adds or removes a value directly. Every selected
  value across every attribute is removable from the existing "active
  filters" chip row below, so the dropdown itself only ever adds. The facet
  block also moved from the sidebar to a bar above the product grid — the
  filters a shopper cares about most once a category is picked, given more
  room than a 15rem sidebar column allows.

- **"The image field has invalid image dimensions" named neither the
  requirement nor what was uploaded.** `ProductImagesRelationManager`'s
  single-image field now carries an explicit message stating the minimum
  pixel size, plus helper text stating it before the file picker even
  opens, so a rejection is not the first time the requirement is seen.

- **A product could be given two values of a variation-only attribute as a
  product-wide fact — "this product is both Size S and Size L".** Reported
  live via the "Product details" picker. `SetProductAttributeValues`'s own
  axis-clash guard only checked *that product's own* declared axes, so a
  product that had simply never listed Size as an axis (a sneaker varying by
  `shoe-size` instead, in the live case) had nothing to catch it against.
  New `attributes.is_variation_only` column — a property of the attribute
  itself, not inferred per product — marks Colour, Size, Shoe size, Capacity,
  and Storage as things a customer chooses between and which therefore may
  never be asserted as a single product-wide value, whatever any individual
  product happens to declare. `AttributeForm` gained the matching toggle;
  `fixtures:validate` gained the matching check. Five pre-existing rows this
  would have refused were found and removed.

- **Product weight and dimensions had never once saved through the admin
  panel, and opening a product's edit page erased whatever a seeder had
  set.** `ProductForm`'s `weight_input`/`length_input`/`width_input`/
  `height_input` fields carried `->dehydrated(false)`, intended as "these
  are not columns, keep them off the model". That is not what it does: it
  excludes the field from the submitted payload entirely, so
  `ConvertsMeasurementInput` — whose only job is reading those four keys —
  received nothing and correctly wrote `null` for an absent value. The
  trait already `unset()`s the keys itself, so the flag was redundant as
  well as fatal.

  Both halves are now fixed: the fields dehydrate normally (the trait
  removes them before Eloquent sees them), and `hydrateMeasurementInput()`
  — the trait's new inverse — fills them back from the canonical columns in
  `EditProduct::mutateFormDataBeforeFill()` and the variations relation
  manager's `EditAction::fillForm()`, since a non-column field cannot be
  reached by Filament's default record fill and a blank field on open is
  what turned every save into an erase.

  Found by loading a real edit page and saving one unrelated field, not by
  reading the form definition — which looks correct, which is why Pint,
  Larastan, and the whole existing suite stayed green for the life of the
  bug. Two regression tests now assert the round trip in both directions,
  and `troubleshooting.md` carries the entry.

- **A negative `discount_price` on a product or a variation reached the
  database uncaught, surfacing as an unhandled `QueryException`
  (`chk_product_variations_discount_price_non_negative`) instead of a form
  error.** The check constraints were always correct; the four admin forms
  that write these columns — `ProductForm`'s product fields and its
  create-only variation repeater, plus `ProductVariationsRelationManager`
  (the edit-time path, and the one the live crash actually came through) —
  never had a `minValue(0)` rule, only `decimal:0,2` (decimal places, not
  sign) and `->lt(...)` (which a negative number still satisfies against a
  positive price). The relation manager's `discount_price` field was
  additionally missing `->lt('price')` and `->nullable()` outright, unlike
  its two siblings. Reproduced locally first — the same constraint
  violation, byte-for-byte — before the fix, confirmed clean after;
  `ProductResourceTest`'s new case asserts a form error on the field, not a
  500, and that no variation row is left behind.

- **A product whose `min_order_quantity` exceeds current stock let the
  customer click "Add to cart" with no warning it would fail.** Not a
  crash anywhere — `AddToCart` already refused it cleanly server-side,
  `InsufficientStockException` caught and shown as a normal form error,
  proven by calling `addToCart()` directly (the same path a raw request
  bypassing the UI hits) before this fix — but the stepper defaulted to
  the *minimum* (floored there deliberately, so it never shows a ceiling
  no valid order could meet) rather than to real stock, and the button's
  own `@disabled` only checked `stock === 0`, not stock below the minimum.
  A product with `min_order_quantity=5` and 2 in stock showed a fully
  clickable button pre-filled with a guaranteed-to-fail quantity, and
  nothing on the page said why. Added an explicit warning ("Only 2 in
  stock — below the minimum order of 5") and extended the disabled
  condition to match. 3 new tests in `ProductDetailsQuantityTest`,
  including one that bypasses the stepper's own value entirely to confirm
  the server-side refusal still holds regardless of what the disabled
  button would have prevented.

- **`ProductDetails::$variationId` crashed on `?v=` too large for PHP to
  represent as an `int`** — confirmed live, `curl` against a real product
  page, while writing the methodology doc above specifically because
  `$quantity` on the same component had just been fixed for the same class
  of bug and this one had not been checked yet. `TypeError: Cannot assign
  float to property ... of type ?int` — an oversized numeric string decodes
  to a `float` during `#[Url]` hydration, which `?int` then refuses, before
  `mount()`'s own "fall back to a valid default variation" logic ever runs.
  Widened to `mixed`, normalised explicitly at the top of `mount()`. 3 tests
  in `ProductDetailsVariationIdTest`, using `$this->get()` against the real
  route rather than `Livewire::test()->set()` — the crash happens during
  `#[Url]` hydration on a fresh request, which a property set after the
  component already exists does not reproduce. Confirmed red without the
  fix, green with it.

- **Price and rating filters on `/catalogue`**, plus a hierarchical
  category sidebar replacing the flat 173-row alphabetised `<select>`.
  `docs/reference/write-rules/catalogue-filters.md` is the full contract —
  every filter, how they combine, and exactly what happens on malformed or
  malicious input — written because "what happens if X" kept needing a
  real answer rather than an assumption once there were five filters
  instead of two.

  **Price** filters `regular_price` (the sticker price), not the
  discount-window effective price `ResolveProductPrice` resolves for
  display — a deliberate cut documented in the reference page, not an
  oversight: expressing `windowActive()`'s date-window logic a second time
  in raw SQL is exactly the duplicate-implementation risk ADR-0014 already
  names. **Rating** excludes a product only when it has approved reviews
  *and* their average is below the selected tier (`4★`/`3★`/`2★`/`1★` &
  up) — a product with zero approved reviews is shown at every tier,
  including the strictest, and an unapproved review does not count as
  "having one" either.

  `$minPrice`, `$maxPrice`, and `$minRating` are all declared `mixed`, not
  `?float`/`?int` — the same reasoning as `ProductDetails::$quantity`
  (below): Livewire assigns whatever the client sends before any of the
  component's own code runs, and a strictly-typed numeric property throws
  before sanitisation ever executes. Verified live, not only against Pest:
  `?minPrice=99999999999999999999999999999999`,
  `?minRating=<script>alert(1)</script>`, and `?minPrice=' OR 1=1--` all
  return `200` with no crash and no effect on the query.

  **The category sidebar is now a real tree**, not a flat list — every
  category depth-first, parent immediately before its own children,
  indented by depth (`— ` per level, the standard technique for a native
  `<select>`, which cannot render custom per-`<option>` markup).
  `ResolveCategoryFamily::orderedTreeWithDepth()` builds it; never orphans
  a row, because a category's family count can only be smaller than what
  its own ancestors' sums include, so anything worth showing already has
  every ancestor up to the root in the same filtered set. The header
  mega-menu (added earlier this session) stays two levels deep by design —
  fast browsing, not full-depth navigation; the sidebar is how a category
  past that depth (`Air Fryers`, 4 levels down) is actually reached.

  17 tests across `ResolveCategoryFamilyTest`,
  `ProductListCategoryFilterTest`, and the new
  `ProductListPriceAndRatingFilterTest` cover the family-resolution walk,
  the tree ordering, and every malformed/malicious-input case pinned as
  permanent regression coverage.

- **Category filtering by family, not by exact match, and by slug in the
  URL, not id.** Selecting a parent category

- **Category filtering by family, not by exact match, and by slug in the
  URL, not id.** Selecting a parent category (from the catalogue sidebar or
  the new header mega-menu) now shows every product under it — its own
  direct products plus every descendant's, at any depth — instead of only
  products assigned to that exact row. The real seeded tree is 4 levels
  deep (Clothing → Men → Tops → T-Shirts, confirmed by walking it, not by
  sampling a shallow branch and assuming), and a non-leaf category can hold
  products of its own alongside its children's (Garden: 3 direct, plus 2 on
  Mowers and 2 on Watering — selecting Garden now correctly reads 7, not 3).

  `App\Support\ResolveCategoryFamily` is the one place this walk happens —
  a function, not an Action, per ADR-0014's reasoning for read-time
  resolvers. Loads the whole `product_categories` table once per request
  (173 rows in the seeded catalogue) and walks an in-memory adjacency map,
  rather than a recursive CTE or one query per tree level.

  `ProductList`'s `#[Url]` property is `categorySlug` now, not `categoryId`
  — `?category=mens-jackets`, not `?category=5` — because a link a customer
  shares or bookmarks should read as a name. The sidebar's per-category
  counts follow the same family rule as the filter itself, so a count never
  promises more than clicking it delivers. A slug matching nothing (stale
  bookmark, hand-edited URL, deleted category) falls back to the
  unfiltered catalogue silently, the same graceful behaviour the old
  id-based lookup already had for an id that did not exist.

  **New header mega-menu** — hover (or focus, or tap) "Catalogue" to see
  every top-level category on the left; hovering one reveals its own
  children on the right, replacing the flat leaf-only `<select>` as the
  primary way to *browse* the catalogue. That `<select>` stays on
  `/catalogue` itself for *narrowing* an already-loaded result set — a
  different job, both still useful. `App\Support\ResolveCategoryFamily::
  topLevelWithChildren()` backs it, one level deep only (the menu shows
  master category → subnodes, not the full 4-level tree on hover).

  Verified live, not only against Pest: `?category=' OR '1'='1` and
  `?category=<script>alert(1)</script>` both return 200 with no reflection
  and no query change from the unfiltered count — `categorySlug` only ever
  reaches a parameterised `where()`, structurally, not by input filtering.
  12 tests across `ResolveCategoryFamilyTest` and
  `ProductListCategoryFilterTest` pin the family-resolution logic and the
  injection/XSS/oversized-input cases as permanent regression coverage,
  not just a manual check.

- **Admin dashboard widgets** — seven widgets under `app/Filament/Widgets/`,
  discovered automatically by `AdminPanelProvider`'s existing
  `discoverWidgets()`: `RevenueOverview` (30-day revenue, orders, items sold,
  average order value, and return rate, each with a trend against the prior
  30 days), `RevenueTrendChart` (90-day daily revenue line), `OrdersByStatusChart`
  (every current order by status — the live pipeline, not a time-boxed
  slice, coloured to match `OrderStatus::getColor()`), `TopSellingProductsTable`
  (units sold, read from `order_items`' own snapshot columns so a since-deleted
  product still appears), `ReturnsAndDamageTable` (ranked by loss rate —
  returned+damaged as a share of sold — not raw count, so a low-volume
  product with a high return rate isn't buried under a high-volume one with
  a low rate), `ReviewsOverview`, and `RatingDistributionChart`.

  Every widget aggregates with `SUM`/`COUNT`/`GROUP BY` directly against the
  database rather than loading Eloquent models — there is no N+1 to
  eager-load around because no relation is ever touched per-row.

  Fixed one real seeder bug this surfaced immediately: `DemoOrderSeeder`
  backdated a payment's `created_at`/`updated_at` to match the order's
  history but never touched `paid_at`, so every seeded `paid_at` read as
  "whenever the seeder last ran" rather than the order's real date — which
  would have made `RevenueTrendChart` show eight months of history crammed
  onto a single day. `amazoff_demo` re-seeded after the fix;
  `docs/reference/schema/demo-data.md`'s revenue figures were not affected,
  since it records counts, not sums.

  Nine tests in `tests/Feature/Filament/DashboardTest.php` render each
  widget with real factory-created rows rather than an empty table, on the
  reasoning that a query clean under Larastan still breaks on its first
  real request — one genuinely did: `TopSellingProductsTable`'s grouped
  query triggered `only_full_group_by` because Filament appends a
  primary-key tiebreaker `ORDER BY` by default, fixed with
  `->defaultKeySort(false)`. The tests stop short of asserting widget
  *content* through the assembled `/admin` dashboard page itself — Filament
  widgets are Livewire-lazy, so the page shell's own HTTP response never
  contains their content, and asserting around that would test Livewire's
  lazy-loading rather than anything the query itself does.

- **Storefront wordmark** — the header and footer's plain "Amazoff" text is
  now a styled two-tone "Amaz*off*" echoing `public/images/logo.png`'s own
  logo (dark "Amaz" + Amazon-orange "off", plus a small curled underline
  arrow after the logo's own smile swoosh). The orange is sampled directly
  from the logo's pixels (`(254,160,1)` → `oklch(0.78 0.17 68)`), added as
  `--color-brand-orange` in `resources/css/app.css` — a deliberate,
  explicitly-scoped exception to that file's own "one committed accent"
  comment, used nowhere else on the storefront.

- **Admin panel branding** — `AdminPanelProvider` sets `->brandName()` and
  `->brandLogo()` to the same two-tone "Amaz*off*" wordmark as the
  storefront header (`resources/views/filament/components/brand-logo.blade.php`),
  and a **View site** link in the user menu (`sort(-2)`, before the
  Profile/Sign-out items Filament registers itself) — the only way back to
  the storefront from the panel otherwise being to type the URL by hand.
  The storefront's `--color-brand-orange` CSS custom property doesn't reach
  the admin panel — Filament ships its own compiled CSS bundle, independent
  of the storefront's Vite build — so the logo partial uses the same
  sampled hex literally instead.

- **`ListCarriers`** gets a page subheading, `*COD = Cash on delivery` —
  the table's `cod_fee` column has no explicit `->label()`, so it renders
  as Filament's auto-cased "Cod fee" with the abbreviation never spelled
  out anywhere else on the page.

- **Storefront authentication** — login, registration, logout, and password
  change, as Livewire components under `app/Livewire/Auth/`. Laravel's own
  guard and session throughout: no Breeze, Jetstream, or Fortify, because
  implementation standard #9 allows at most one authentication library and
  Filament's panel login was already authenticating this same `web` guard.

  **Filament's `->login()` is removed from `AdminPanelProvider`.** Two
  password forms against one guard was a second surface to audit for nothing
  — a staff member signing in at `/login` is already authenticated for the
  panel. Filament now redirects a guest at `/admin` to the storefront
  `login` named route, verified returning a 302 to `/login`.

  Staff reach the panel through an **Admin panel** link in the header
  account menu, gated by calling `canAccessPanel()` itself rather than by a
  separate `hasRole()` check. One source of truth, so the link cannot
  advertise a door the gate then refuses; the gate remains the security
  boundary, the link is only the affordance.

  Security decisions worth naming, all covered by tests: `is_active` is part
  of the `Auth::attempt()` credentials rather than a check afterwards, so a
  deactivated employee gets the same failure as a wrong password instead of
  one that confirms the address exists; a failed login puts its error on
  `email` and never on `password`, for the same anti-enumeration reason;
  login is rate-limited five attempts per email+IP per minute, keyed on both
  so one attacker cannot lock a real customer out of their own account;
  `session()->regenerate()` runs unconditionally after a successful sign-in,
  since the pre-login session id is exactly what a fixation attack plants;
  logout is POST-only, because a GET logout is triggerable by any `<img>` on
  any page the user visits; and a password change requires the current
  password and then calls `logoutOtherDevices()`, a password change being
  the standard response to "someone else may be signed in as me".

  Registration creates an account holding **no role at all** rather than a
  `customer` role — a registered customer is the default authenticated
  state, and access to their own orders is an ownership check in a policy,
  not a permission (§3). The password is left to the model's `hashed` cast
  rather than `Hash::make()` in the component; hashing twice would make the
  password unusable, which `AuthenticationTest` pins directly.

  Nine tests in `tests/Feature/Auth/AuthenticationTest.php`, added to CI
  shard 2 in the same change. Each proves something ours rather than
  Laravel's — `Auth::attempt()` and `Password::defaults()` are not
  re-tested. The deactivation test was confirmed to go red with the
  `is_active` guard removed, per the rule that a test never observed failing
  proves nothing.

  Still outstanding: forgot/reset password by email, and email verification
  (`users.email_verified_at` exists in the schema and nothing sets it).

- `docs/reference/local-access.md` — seeded account credentials, service
  URLs, and the full storefront/admin route map for a local run. Verified
  against a running stack rather than read off the code: all four accounts
  logged in, `canAccessPanel()` checked per role (including the customer
  correctly denied), and every route taken from `route:list`. Records two
  facts as facts rather than smoothing them over — `OrderResource` has no
  edit page and nothing in the panel calls `TransitionOrderStatus`, so §37
  criterion 16 is unreachable through the UI despite the Action, its
  policy, and its three concurrency tests all existing; and `ViewOrder`
  renders an `EditAction` pointing at a route that is not registered.

- About and contact pages, plus the footer newsletter signup — the two dead
  links the storefront chrome had been shipping since the catalogue slice.

  `/about` is a `Route::view`, not a Livewire component. It holds no state and
  runs no query, and ADR-0014 scopes a component to pages that do; reaching
  for one here would have been the pattern applied out of habit.

  `Contact\ContactForm` writes `ContactMessage` directly, with no Action.
  ADR-0007's test is a write spanning more than one table or an invariant the
  schema cannot hold, and a contact message is neither — the same reasoning
  that keeps `Brand` and `Tag` on Filament's default CRUD. `user_id` comes
  from the session rather than the form, so a guest submits an unattributed
  message and nobody can attribute one to another account. A honeypot field
  absorbs bots without a third-party service.

- `App\Actions\Contact\SubscribeToNewsletter` — the newsletter signup does go
  through an Action, and the difference from `ContactForm` is the point.
  `NewsletterSubscriberForm` in the panel also writes `status`, so two callers
  decide it, and only one of them knew that subscribing again reverses an
  unsubscribe. ADR-0007's carve-out for a single-table save is explicitly
  conditioned on there being no second writer; there is one here.

  Idempotent through the UNIQUE index on `email` plus a caught violation
  rather than `exists()` then insert, which two simultaneous submissions of
  one address both pass. The update on that path skips nulls, so a guest
  re-subscribing cannot blank a `user_id` an account already owns.

  That last part closes a real gap rather than a hypothetical one: the
  previous inline version never wrote `user_id` on re-subscribe, so an address
  subscribed before registering stayed permanently unlinked from the account
  that owned it, and an erasure request scanning by user would not have found
  it. `explanation/gdpr.md` lists `newsletter_subscribers` as personal data.

  Six tests in `tests/Feature/Actions/Contact/`, registered in CI shard 2. The
  two covering the resurrection were confirmed to fail with the update in the
  catch block removed.

- The product detail page — `Catalogue\ProductDetails` at
  `/products/{product:slug}`, closing §37 criterion 4's product side. Gallery
  with thumbnails and prev/next arrows, an attribute picker that resolves a
  selection to a variation, price and stock for that variation,
  specifications, approved reviews, an arbitrary-depth category breadcrumb,
  and add-to-basket through the existing `AddToCart` Action.

  The selection is held as **one** value — `variationId`, in the URL as `?v=`
  — and which attribute values are picked is derived from it. An earlier
  draft stored both a `selectedValues` map and the variation id; they can
  disagree the moment a shared link arrives with `?v=` and nothing has
  populated the map. One source of truth removes the failure rather than
  synchronising it. `variation()` is the validation gate: an id resolves only
  if it is in `variations()`, which is already scoped to this product and to
  `is_available`, so an id belonging to another product returns null instead
  of leaking its price. Same reasoning as the catalogue's sort allow-list,
  and for the same reason — `#[Url]` makes a property attacker-controlled.

  Two decisions worth naming. `gallery()` returns the variation's own images
  **followed by** the product's remaining ones rather than one or the other:
  ADR-0013 makes an image with no pivot row a product-level image,
  legitimately shown for any variation, and returning only the variation's
  own set collapsed the thumbnail strip and the arrows together whenever a
  variation owned a single photo — which reads as the page breaking rather
  than as a shorter gallery. And `addToCart()` does not pre-check stock:
  `AddToCart` re-validates availability, minimum quantity and stock inside a
  transaction, while `$this->stock` is read outside one and is stale on
  render. Two checks would be two answers that can disagree, and only one of
  them is authoritative.

- `App\Support\ResolveProductPrice` and `App\Support\ProductPrice` — the
  product-level counterpart to `ResolveVariationPrice`, and the value object
  both now return. `ProductPrice::make()` derives all four display values
  (price to charge, price to strike through, whether a sale is live, and the
  whole-percent saving) from one reading of the discount window, so nothing
  downstream can hold a `percent` that disagrees with its `current`.

  This removed a real duplication: `ProductList::discountIsActive()` had its
  own copy of §11's discount-window rule, which `ResolveVariationPrice`
  already owned. Two implementations of one rule can disagree, and the way
  they disagree is expensive — a card advertising a sale price the cart then
  refuses to honour. The window now lives in
  `ResolveProductPrice::windowActive()` and `ResolveVariationPrice` calls
  through to it.

  The percentage is computed with `bcmath` rather than float. The rounding
  boundary is reachable with ordinary prices: 200.00 down to 189.00 is
  exactly 5.5%, and in binary floating point that lands either side of the
  boundary depending on representation error, so the badge would read −5% or
  −6% unpredictably.

- `App\Support\ResolveCurrentCart` — finds or opens the current visitor's
  cart, keyed by `user_id` when signed in and `session_id` when not.
  `expires_at` is deliberately left null: `ExpireCarts` skips null rows, so
  this preserves today's behaviour exactly rather than choosing a guest-cart
  lifetime, which is a policy call belonging with the cart page.

- The storefront — first customer-facing slice, and the first code in this
  project outside the Filament panel. Livewire 3 + Tailwind 4, with
  `Catalogue\ProductList` at `/catalogue` covering §37 criteria 2 and 3
  (browse, search, filter, sort): search across name and short description,
  category and brand facets with live counts, in-stock and on-sale toggles,
  three sort columns, dismissible filter chips, and pagination. Every
  filter is `#[Url]`, so a filtered catalogue is a shareable link and the
  back button works.

  `ADR-0014` records the decisions this slice sets precedent for, because
  every remaining page copies its shape: reads query Eloquent directly from
  the component while writes still go through Actions (ADR-0007 is a rule
  about writes and stays one); filter state lives in the URL, never the
  session; a page that filters *and* counts holds one definition of
  "filtered"; and anything a URL-bound property reaches into a query is
  allow-listed, because `#[Url]` makes every public property
  attacker-controlled — `?sortBy=` lands in `orderBy()` otherwise.

  Two details worth naming separately. Facet counts are computed against
  every filter *except* the facet's own dimension (`applyFilters($q, skip:
  'categoryId')`), because counting against all of them makes every
  unselected category read zero the moment one is picked, and counting
  against none of them promises results the grid will not show; options
  that would count zero are dropped rather than shown greyed. And the
  availability figure subtracts `reserved_quantity` from
  `current_quantity` — a unit held for someone mid-checkout is not one this
  customer can buy, and showing it is how a catalogue promises stock that
  `ReserveStock` then refuses at the last step of checkout. The catalogue
  figure is explicitly not authoritative; it is read outside a transaction
  and stale on render. `ReserveStock` under ADR-0008's lock remains the only
  thing that decides.

- `DemoSeeder` — a catalogue that looks like a shop rather than a fixture.
  15 curated products across 16 categories (two levels) and 10 brands, with
  4 attributes / 15 values, articles, tags, coupons, and reviews. Products
  are created through `CreateProduct` and `AddProductVariation` rather than
  written directly, so the seeder exercises the same invariants the panel
  does and cannot produce a product the application considers invalid.

  It deviates from ADR-0003's JSON-fixture format deliberately and says so
  in its own docblock: the fixture format exists so test data is reviewable
  and diffable, and 15 hand-curated products with prose descriptions are
  neither improved by being moved into JSON nor covered by the tests that
  format serves. `purge()` truncates catalogue tables only, with
  `SET FOREIGN_KEY_CHECKS=0`, and explicitly leaves users and orders alone.

- `App\Support\PlaceholderImage` — deterministic SVG product imagery, drawn
  from the product's own name and category. ADR-0003 rules that no image
  binaries enter git; scraping a real shop would republish someone else's
  photographs and an external placeholder service would make seeding need
  network access. Hue comes from `crc32($name) % 360`, so the same product
  is the same colour on every re-seed, and one of 13 category silhouettes
  (headphones, speaker, turntable, keyboard, mouse, monitor, pan, kettle,
  lamp, backpack, tent, tool, box) gives the grid enough shape to read as
  merchandise. A contact shadow rather than a floating shape, because a
  grid of initials-in-a-box reads as a wireframe.

- Storefront chrome and design tokens — `x-site.header` (sticky, blurred,
  drawn SVG logo, category nav, cart badge, Alpine mobile drawer) and
  `x-site.footer`, on a layout with a skip link and a real `<main>`
  landmark. `resources/css/app.css` defines the palette as `@theme` tokens:
  an `ink` neutral ramp tinted toward the accent rather than pure grey, one
  committed `marine` blue, two radii, one easing curve. Two animations
  only — a staggered card entrance and a single hover sweep — both disabled
  under `prefers-reduced-motion`.
- `CatalogueStressSeeder` (`database/seeders/Stress/`) — thousands of
  additional products for catalogue-scale query-plan, pagination, and
  search testing, companion to the existing order-volume `StressSeeder`.
  Inserts directly (`DB::table(...)->insert()`) rather than through
  `CreateProduct`/`AddProductVariation`, at chunked-batch scale; every
  generated product still gets exactly one `is_default` variation and
  starts with `reserved_quantity = 0`, verified by placing a real order
  against a stress-generated product through `CreateOrder` directly, not
  assumed. Every product's single image row points at one shared
  placeholder file (copied from `public/images/logo.png` on first run,
  not a real photo) — catalogue-scale testing needs row count and query
  shape, not visual fidelity, and an API call per stress product would
  exhaust any free-tier image source in minutes. Categories and brands
  are reused from the existing pool rather than created per product.
  Opt-in only, like `StressSeeder`: never wired into `DatabaseSeeder`,
  never run in CI. Recorded as a deferred decision, not an oversight:
  generated variations carry no `attribute_values`
  (`docs/reference/schema/open-schema-questions.md` #6 — category/tag-driven
  attribute assignment does not exist for real catalogue content either).

### Changed

- **CI `test` job rebalanced from 2 shards to 4.** `tests/Feature/Filament`
  and `tests/Feature/Auth`'s growth this session left shard 2 carrying
  roughly 2x shard 1's measured local time (55s vs 28s) on its own —
  `docs/how-to/use-ci.md`'s own stated trigger for a re-balance
  ("visibly outruns the others"), not a scheduled maintenance pass.
  `tests/Feature/Actions` was the single heaviest concentration and moved
  off shard 2 entirely, split further into shard 3 (`Cart`, `Catalogue`)
  and shard 4 (everything else under `Actions`) once it turned out to be
  uneven on its own too. Verified every one of the 52 test files under
  `tests/Unit`/`tests/Feature` resolves to exactly one shard — no gaps, no
  file matched twice. `use-ci.md` updated to match: shard count, the
  placement rule for a new test file, and the "reproduce a shard locally"
  section.

### Fixed

- **`ProductDetails`'s quantity stepper crashed on a number too large for
  PHP to represent as an `int`** — a 500, uncaught `TypeError: Cannot
  assign string to property ... of type int`, from typing a sufficiently
  long number into the quantity box. `public int $quantity` meant Livewire
  assigned the raw request value to a strictly-typed property before any of
  the component's own code ran; the number field's `min`/`max` HTML
  attributes are not a server-side boundary — `wire:model` sends whatever
  the client sends. Widened to `public mixed $quantity`, so hydration can
  never throw regardless of input shape, with all sanitisation moved into
  `updatedQuantity()`: non-numeric, decimal, or unrepresentable input resets
  to the product's `min_order_quantity` rather than being partially
  clamped, and a value within PHP's range but past `min_order_quantity` or
  available stock is floored/ceilinged to the nearest legal value.
  `AddToCart` still re-validates minimum and stock independently — this
  only stops a malformed value from ever reaching that call or sitting in
  the input looking valid. `min_order_quantity` is now shown explicitly
  under the add-to-cart button when it is greater than 1, and the number
  input carries real `min`/`max` attributes matching it and current stock
  (UX affordance, not the actual guard). 6 tests in
  `ProductDetailsQuantityTest` cover the crash case and the sanitisation
  rules; the crash case was confirmed to reproduce against the un-widened
  property type before the fix landed.

- **Every route 500'd with `tempnam(): file created in the system's
  temporary directory`** after the laptop migration — storefront and
  `/admin` alike, with no `storage/logs/laravel.log` written at all.
  `storage/` and `bootstrap/cache` arrived owned by the host user (uid
  1000, `drwxrwxr-x`) while PHP-FPM's request workers run as `www-data`
  (uid 33), so the compiled-view write was refused. Three things hid it:
  the message names `/tmp` (world-writable, so ruled out first),
  `docker compose exec` runs as root so every manual permission check
  passed, and Laravel could not log the failure because logging needs the
  same directory it had just been denied. Fixed with `chgrp -R www-data`
  plus `chmod -R g+w` and setgid on the directories so new files inherit
  the group. Documented in `troubleshooting.md`, including the rule that
  a suspected permission error in this container must be probed as
  `www-data`, never from the default root shell — the root shell cannot
  reproduce it by construction.

- **`phpstan.neon` only ever scanned `app/`** — every "Larastan clean"
  claim made about `database/seeders/` this session was checking nothing,
  since the seeder reorg into `Demo/`/`System/`/`Stress/` and every seeder
  written or edited since landed outside Larastan's actual scan path.
  Added `database/seeders` to `paths`. Running it for real immediately
  found genuine bugs that had shipped silently: `DemoOrderSeeder` accessed
  `$item->productVariation`/`$item->quantity`/`$event->status` on
  untyped `Model` instances from an unannotated `foreach`, and carried an
  unused `TOTAL_ORDERS` constant; `DemoReviewSeeder`'s declared array
  shape for its (reviewer, product) pairs didn't match what the code
  actually built — it claimed `order_item_id` and never set it, and used
  `order_created_at` without declaring it, which `Carbon::parse()` was
  silently tolerating at runtime but is exactly the kind of drift static
  analysis exists to catch. All fixed; re-seeded the full demo pass
  afterward and confirmed every table still lands on its exact target
  numbers — the type annotations were wrong, not the runtime behaviour.

  Also added a scoped `ignoreErrors` entry for `database/seeders/*`:
  `Illuminate\Database\Seeder`'s `$command` property is declared
  `@var \Illuminate\Console\Command` (non-nullable) but is never
  initialized until `setCommand()` runs — the framework's own `run()`
  guards every use with `isset($this->command)` for exactly that reason.
  Every seeder in this codebase already uses `$this->command?->`
  correctly; Larastan trusts the docblock literally and flagged all of
  them as a redundant nullsafe call. A stub inaccuracy in one base class,
  suppressed by name and scoped to seeders only — not a blanket allowance
  for nullsafe operators elsewhere.

- `demo:fetch-images` — closes the gap where every one of the 182
  `product_images` rows pointed at a placeholder path with no real file on
  disk, breaking every product image in the browser. Downloads a real
  photo per row from Pexels, re-validated by hand against the exact
  constants `ProductImagesRelationManager`'s form enforces (the checks
  live only in the Filament form layer; this command bypasses that form
  the same way `FixtureLoader` does for the rest of the catalogue).

  Went through three sources before landing on one that actually works,
  and the command's own docblock keeps that record so nobody repeats it:
  Unsplash worked correctly (0 contamination across 25 files) but its
  50-requests/hour free tier meant several runs spread across a day to
  cover ~162 products; an Apify `hooli/google-images-scraper` actor was
  tried next for its lack of an hourly cap, and a real run showed roughly
  40% of "downloaded images" were actually a hotlink-protection
  placeholder graphic that arbitrary scraped CDN hosts serve instead of
  the real photo — a real, valid, correctly-sized image, just not a
  product photo. A GD colour-variance detector was built to catch this
  and still missed most of them on a second full run, because JPEG
  compression and antialiased rotated placeholder text manufacture enough
  colour variety to defeat that kind of heuristic — confirmed by
  duplicate-hash analysis on real output, not assumed. Pexels (25,000
  requests/hour, own CDN rather than arbitrary hosts) finished the full
  162-product set in one run, 0 failures, verified clean by the same
  duplicate-hash method plus direct visual inspection across every
  duplicate cluster and a spread of singles: 159 distinct photos, 23 rows
  legitimately sharing a generic category photo where Pexels had nothing
  more specific.

### Changed

- **`database/seeders/` split into `Demo/`, `System/`, and `Stress/`
  subfolders**, namespaced accordingly (`Database\Seeders\Demo\...`,
  `Database\Seeders\System\...`, `Database\Seeders\Stress\...`).
  `DatabaseSeeder` stays at the root — it is Laravel's entry point and
  `migrate:fresh --seed`'s implicit target. Grouping: `Demo/` is every
  seeder that produces presenter-facing demo content plus the two
  reference seeders its fixtures resolve against (`CatalogueReferenceSeeder`,
  `ContentReferenceSeeder`); `System/` is what CI, local, and production all
  need regardless of demo content (`CarrierSeeder`, `PermissionSeeder`,
  `RoleSeeder`, `UserSeeder`); `Stress/` is `StressSeeder` alone, kept
  separate from `Demo/` despite subclassing `DemoOrderSeeder` because it
  produces no narrative content and must never be mistaken for something
  the demo run needs.

  **`db:seed --class=` needs the fully-qualified class name now** — a bare
  basename like `--class=DemoSeeder` no longer resolves once a seeder is
  namespaced under a subfolder; every doc and script has to pass
  `--class="Database\Seeders\Demo\DemoSeeder"` (quoted, since an unquoted
  backslash is a shell escape character). Updated everywhere this was
  found: `seed-the-database.md`, `demo-data.md`, `article-fixture-format.md`,
  `edit-a-role.md`, `troubleshooting.md`, `tech-stack-overview.md`, the
  docblocks inside `DemoSeeder`/`DemoArticleSeeder`/`StressSeeder`
  themselves, and 18 test files (`use Database\Seeders\PermissionSeeder;`
  and siblings) that imported the pre-move classes directly — those were
  silently broken until fixed, since a missing class only surfaces when the
  test file actually runs, not at edit time. `misc/`'s two session briefs
  (gitignored, not shipped) were left as historical record with a note at
  the top rather than rewritten, since they document a plan already
  executed under the old paths.

### Added

- `App\Support\ProtectedSkus` and
  `database/fixtures/reference/protected-skus.json` — a guard against a
  silent data-destruction trap found while planning the transactional
  seeding pass. `TransitionOrderStatus` on `=> Shipped` composes
  `CompleteSale`, which moves stock from reserved to sold and permanently
  drops `current_quantity`, and `CreateOrder` reserves at creation. So any
  seeder that samples order lines at random consumes exactly the SKUs
  `demo-data.md` documents as "out of stock" and "exactly one left" — and
  the failure is invisible: the seeder succeeds, the orders look correct,
  and the presenter's lookup table is quietly wrong.

  The list is JSON rather than a PHP const so it is readable by anything
  that needs it, not only by the one class that must not violate it.
  `assertSelectable()` throws rather than returning false, so a protected
  line cannot be silently skipped into a set smaller than the distribution
  it claims to have written. `floorFor()` carries the `min_order_quantity`
  floors, which are deliberately *not* an exclusion — those products
  belong in seeded orders.

  Verified against a freshly seeded catalogue rather than by reading the
  code: all 19 excluded SKUs resolve to real rows (14 products, 5
  variations), all 5 `min_order_quantity` floors match the database, the
  guard blocks exactly 19 of 219 variations and leaves 200 selectable,
  `assertSelectable()` throws on a protected line and passes an ordinary
  one, and the states being protected are real (`current_quantity` 0 and 1
  respectively, `CLM-0016.is_available` false). A protected variation may
  be blocked through its parent product rather than its own SKU —
  `CLM-0016-STD` is refused because `CLM-0016` is unavailable — so callers
  pass the variation and let the guard do both lookups.

  Not yet exercised by a caller: no seeder consumes it, because
  `DemoOrderSeeder` does not exist yet.

- The transactional demo-data pass: `DemoAddressSeeder` (78 addresses
  across 60 of 100 customers), `DemoEngagementSeeder` (80 newsletter
  subscribers, 25 hand-written contact messages), `DemoOrderSeeder` (140
  orders built by checking out real carts through `CreateOrder` and
  `TransitionOrderStatus`, never fabricated with `Order::factory()`),
  `DemoReviewSeeder` (90 reviews drawn only from delivered orders' own
  purchases), `ContentReferenceSeeder`'s tag vocabulary extended for the
  general-marketplace catalogue, and 24 article fixtures across 4 batches.
  `StressSeeder` for table-size testing, built as a thin subclass of
  `DemoOrderSeeder` reusing its `loadPools()`/`seedOneOrder()` rather than
  a second implementation.

  Verified against the live database, not the seeders' own output: 140/140
  orders on the exact status distribution planned, every `PaymentStatus`
  and `ShipmentStatus` case represented at least once (including `Failed`
  with 2 of 4 recovering to `Paid`, and `PartiallyRefunded` with 2 stacked
  refunds), 19 coupon redemptions including `ONEUSEONLY`'s first-ever one,
  90 reviews on the exact rating distribution with 62 approved / 28
  pending, 24 articles on the exact status distribution. `StressSeeder` run
  at 2000 orders: 1734 succeeded, 266 refused cleanly via
  `RuntimeException` as the ~200-variation selectable pool ran low against
  2000 orders' demand — zero protected-SKU violations and zero inventory
  overdraw (`reserved_quantity > current_quantity`) across the resulting
  1922 total orders, confirming `ProtectedSkus` holds under real load, not
  only the 140-order demo scale it was designed for.

  Two real bugs found only by running against live data, not by reading
  the code: `CreateOrder`'s `$actor` parameter is not an authorization gate
  like every other Action's — it is the checking-out customer, written to
  `orders.user_id` and used to scope `source_address_id`. Passing `null`
  for a registered customer (the pattern correct everywhere else in this
  pass) threw `ModelNotFoundException` on every order using a saved
  address. And a `Refunded` order-status walk that went `Shipped =>
  Returned` directly, skipping `Delivered`, left a COD order with no
  payment ever opened (COD is marked paid on remittance, i.e. at
  `Delivered`, never before) — so a `Refunded` order-status target with no
  refundable payment is a contradiction the walk now avoids by routing
  every path through `Delivered`.

  Not built in this pass: a real Stripe test-mode round trip. No
  checkout-session controller or webhook route exists yet — `RecordPayment`
  and `TransitionPaymentStatus` are called directly, proving those two
  Actions' locking and status-transition rules, but not a real Stripe API
  call. `stripe/stripe-php` is installed; nothing in `app/` calls it yet.

- `docs/reference/schema/demo-data.md` — the presenter-facing index of what
  the seeded catalogue actually contains: the exact SKU for every coverage
  state (out of stock, one left, each discount phase, unavailable, 5+
  variations, ≥90-char name, min-order-quantity, zero-attribute products),
  the two variation-gallery shapes (`PWR-0012` — one image shared by three
  variations; `PWR-0001` — one variation carrying two images) with a
  reproduction query, the coupon table, and what's deliberately absent
  (orders, images, a discount at exactly the boundary). Verified against a
  live seed, not the fixture JSON — every count and SKU on the page was
  read back from the database after `migrate:fresh --seed` plus every demo
  seeder.

  Two demo-data gaps surfaced and were closed while building it: no product
  had a *scheduled* discount despite the coverage matrix requiring one
  (`KIT-0011` now does), and no product had 5+ variations despite the same
  requirement (`CLM-0001` gained a fifth). `PWR-0010`'s ≥90-character name
  was also short by 2 characters — `wc -c` had counted UTF-8 bytes for a
  name containing an em dash, not characters; `mb_strlen`, matching what
  `ValidateFixtures::assertMaxLength()` actually checks, was the correct
  measure and is now what was used to fix it.
- The demo catalogue: 169 products, 219 variations, spanning 122 of the
  173 leaf/branch categories in `catalogue.json` and 33 of 34 brands, in
  `database/fixtures/demo/`. Nine batches (`power-tools`,
  `hand-tools-garden`, `workwear`, `clothing-men`, `clothing-women`,
  `electronics`, `kitchen`, `home`, `sports-beauty-toys`), each its own
  SKU prefix per `SKELETON.md`'s collision scheme.

  Verified rather than assumed: every state in `fixture-format.md`'s
  coverage matrix appears at least once (out of stock, exactly one left,
  discount active/expired/scheduled, 1/3/5+ variations, unavailable
  products, no-image products, a ≥90-character name, `min_order_quantity
  > 1`), plus two variation-gallery shapes not previously called out
  explicitly in the matrix and added there: one product image shared
  across ≥2 variations' galleries (39 instances across the set) and one
  variation carrying ≥2 images in its own gallery (20 instances) — both
  confirmed against the actual `product_image_product_variation` pivot
  after seeding, not only against the JSON shape.

  Demo images referenced under `demo/*.jpg` are not committed — item 11 of
  `fixture-format.md`'s numbered rules states the file does not have to
  exist for a fixture to validate and load. Actual image files, when
  added, belong in `storage/app/public/demo/` (gitignored, already
  reachable at `/storage/demo/...` through the existing `public` disk
  symlink) rather than `public/demo/`, which the `Storage::disk('public')`
  calls `ResolveVariationImage` and `RemoveProductImage` already use would
  not serve.
- `App\Console\Commands\ValidateFixtures::assertLengths()` — see the
  standalone commit; folded in here because the length caps are what the
  demo batches above are validated against.

### Documentation

- **The N+1 rule is now written down** — `CLAUDE.md`'s architecture list,
  `project-conventions.md`, and a new "Eager loading, and the N+1 rule"
  section in `explanation/filament-resources.md`.

  Filament does **no** eager-loading of its own — verified by reading
  `filament/tables`, which contains no `->with()` anywhere. So a
  `TextColumn::make('brand.name')` is one extra query per row and the page
  still renders correctly, which is why it goes unnoticed. Measured on five
  product rows: 6 queries lazy, 2 eager.

  The less obvious half is an accessor that reads a relation:
  `Order::$payment_status` derives from `payment`, so a column showing it
  lazy-loads per row even though the column name contains no dot to hint at
  it. Both are fixed on the table via `modifyQueryUsing()`.

  Records that `preventLazyLoading()` is still deliberately off per ADR-0012,
  and that the rule is enforced by review and query-count tests instead — a
  query-count assertion earning its place only where the relation is hidden,
  since asserting it for a plain dot-notation column would be testing
  Filament's rendering.

  Also lists the ten tables that currently carry a relation column with no
  eager-loading, as one pass worth doing before the demo catalogue makes
  those pages long.

### Changed

- **`orders.payment_status` is derived, not stored.** The column is dropped;
  `Order::$payment_status` now reads through the `payment` relation, falling
  back to `Pending` when no payment row exists.

  It was written exactly once, by `CreateOrder`, and never again — nothing
  else in `app/` ever wrote it — while `payments.status` moved independently
  through `TransitionPaymentStatus`. So the two diverged the moment a payment
  was paid or refunded, and the stale one was what `OrdersTable` and
  `OrderInfolist` displayed and filtered on. Confirmed by walking it: a
  payment reading `paid` left its order reporting `pending`.

  §20 forbids storing `inventories.available()` for exactly this reason, and
  this is the same shape with the same resolution. Not backfilled before
  dropping: the column's values were wrong wherever a payment had moved and
  right only where nothing had happened, so copying them onto `payments`
  would have overwritten correct data with stale data.

  `OrdersTable` eager-loads `payment` in `modifyQueryUsing()` (deriving it
  per row would be one query per row), its filter queries through the
  relation and counts a missing payment as `Pending`, and the column is no
  longer `->sortable()` — there is no `orders` column left to sort by.
  `OrderPaymentStatusTest` covers the derivation, including a structural
  assertion that the column does not exist, so reintroducing it in a later
  migration fails loudly rather than silently shadowing the accessor.

### Added

- Tests for the five previously-untested Actions — `RecordPayment`,
  `TransitionPaymentStatus`, `CreateShipment`, `TransitionShipmentStatus`,
  `CreateProductReview`. 37 feature tests and 7 concurrency tests; every
  guard was deleted and observed failing before being restored, per
  CLAUDE.md's rule that a test never seen red proves nothing.

  Three of the concurrency tests cover money directly. Two concurrent
  partial refunds that individually fit but together exceed the payment:
  without `lockForUpdate()` on `payments` both read `refunded_amount = 0.00`,
  both pass their own cap check, and the payment is refunded past its own
  amount — verified by removing the lock. Its counterpart asserts that two
  refunds which *do* fit together both land and accumulate, so the first
  test cannot pass by the Action simply refusing everything. And two
  simultaneous `CreateShipment` calls on a COD order would otherwise produce
  two consignments, each carrying the full `cod_amount` — the courier
  collecting the total twice on the doorstep.

  `RecordPaymentConcurrencyTest` opens by asserting `payments` has **no**
  `UNIQUE(order_id)`, because the rest of the file only proves the lock while
  that stays true; adding such an index later would otherwise silently turn
  those tests into a test of the index.

  The review race is the odd one out and documented as such: it has no lock
  by design, `UNIQUE(user_id, product_id)` guarantees one row whatever the
  code does, so the count proves nothing. What it proves is *how the loser
  fails* — rewriting the Action as check-then-act keeps the count at 1 and
  still fails, because the loser then gets a raw `QueryException` instead of
  `ReviewNotAllowedException`: a 500 on a review form.

### Fixed

- Two documents still claimed `TransitionOrderStatus` was unbuilt, months
  after it shipped. Root `CLAUDE.md` said *"designed in ADR-0004, **not yet
  built**"* under a non-negotiable architecture rule, and
  `reference/write-rules/order.md` said *"`TransitionOrderStatus` does not
  exist yet"* in its "what does not get written" section. Both now describe
  the Action as built and the only writer of `orders.status` and
  `order_status_histories`.

  The surviving half of each claim was kept rather than deleted with the
  false half: `CreateOrder` really does still land every order at `New`
  regardless of payment method, and nothing calls `TransitionOrderStatus`
  from checkout — because the Stripe and cash-on-delivery first hops
  diverge (`New => AwaitingPayment` against `New => Confirmed`) and
  `CreateOrder` is blind to which applies. That is a deliberate decoupling,
  not a gap, and the correction says so.

  `online-store/.ai/guidelines/project-conventions.md` needed no change —
  the condensed form had always stated the rule correctly. The other four
  pages referencing the Action (`actions.md`, `inventory.md`,
  `concurrency-and-locking.md`, `security-model.md`) were already accurate.

- `FixtureLoader` silently dropped a product's `attributes` field.
  `productColumns()`'s `Arr::except()` stripped it out to build the
  `products` insert, and nothing ever used it afterward — `attribute_product`
  stayed empty for every fixture-loaded product, for every fixture ever
  loaded before this session, invisibly: nothing else in `app/` reads that
  pivot yet, so no test and no panel screen surfaced the gap. Found while
  auditing the 169-product demo catalogue for `demo-data.md` — a
  `whereDoesntHave('attributes')` count that should have matched only the
  genuinely single-SKU products instead matched all 169.

  Fixed with `FixtureLoader::attachAttributes()`, mirroring
  `attachAttributeValues()`'s existing pattern exactly: resolve the fixture's
  attribute slugs against real `attributes.id` rows and
  `syncWithoutDetaching()`. Verified:
  `Product::where('sku','CLM-0001')->first()->attributes()->pluck('slug')`
  now returns `colour, size`; the zero-attribute count correctly dropped to
  48 (matching the products actually authored with `"attributes": []`).
- `AddProductVariation`/`RemoveProductVariation` composing `SetDefaultVariation`
  with `$actor` passed through, rather than `null`. `ProductVariationPolicy`
  gives `create`/`update`/`delete` three separate permissions (unlike
  `ProductImagePolicy`, where they collapse to one `update_product`), so an
  actor holding only `create_product_variation` could create a variation but
  then fail `AuthorizationException` on the automatic first-becomes-default
  promotion, which demands `update_product_variation` — a permission the
  create path never claimed to need. `RecordInventoryMovement`'s own
  docblock states the precedent this should have followed from the start:
  "authorizes nothing — the caller has already authorized what this
  records." Caught by the full Feature suite, not by either Action's own
  test file in isolation — both passed alone because their fixtures granted
  every relevant permission together. Regression test added:
  `AddProductVariationTest`'s "allows an actor holding create_product_variation"
  now asserts the promotion, not just that the create succeeded.
- `ProductResourceTest`'s `'creates a product with a stock row through the
  panel'` — the `Repeater::make('variations')` item in `ProductForm` never
  reached `weight_display_unit`'s own `->default()`, because filling a
  repeater item via Livewire replaces it wholesale rather than merging over
  per-field defaults; a live browser submit always carries the Select's
  value, so the test's omission was under-specifying the form, not a defect
  in it. Same root class of bug as the `is_default` one above: passing
  green in isolation, caught only once the CLAUDE.md-mandated post-change
  gate ran the whole suite.

### Added

- `database/fixtures/reference/catalogue.json` — `CatalogueReferenceSeeder`'s
  vocabulary (categories, brands, attributes) moved out of a PHP const and
  into a document the seeder reads. Categories now nest to **arbitrary
  depth**: the old `slug => [name, children: slug => name]` const could not
  express a third level at all, because its children were plain strings, so
  `clothing > men > tops > t-shirts` was unrepresentable. Verified to depth 4.

  Prompted by a ~100-node marketplace taxonomy needing to replace the 14-node
  hardware tree — and the part most likely to be regenerated in bulk is the
  worst candidate for being a PHP literal, where a generation slip becomes a
  syntax error inside `database/`. The seeder validates as it loads and names
  the offending key on a category missing `slug`/`name` or an attribute with
  an unknown `type`. It adds and updates but never deletes: dropping a
  category with products attached is `DeleteProductCategory`'s guarded
  decision, not a seeder side effect.
- `DemoCustomerSeeder`, `DemoCartSeeder`, `DemoCouponSeeder`,
  `DemoWishlistSeeder` — the four remaining pieces of the demo dataset, none
  of them fixtures. `fixture-format.md` previously documented a four-array
  top-level JSON shape (`users`/`products`/`coupons`/`carts`) that
  `FixtureLoader` never implemented — `loadProduct()` is its only entry
  point, and a file in that shape failed validation with
  `is missing required key [name]` before a row could load. Rather than
  build three more loaders for data that gains nothing from being hand
  authored, these seeders produce it directly: `DemoCustomerSeeder` (100
  factory customers), `DemoCartSeeder` (carts for ~35% of them plus a few
  guest carts, through `TouchCartExpiry` so `expires_at` follows the same
  rule a real cart write does — `null` for a registered customer, `now() +
  guest_ttl_hours` for a guest), `DemoCouponSeeder` (one coupon per required
  state), `DemoWishlistSeeder` (wishlists for ~20% of customers). Doc
  rewritten to match; see its own "What a fixture file actually contains"
  section.

  One coverage state could not be produced: a coupon at
  `total_usage_limit` **reached**. `coupons.times_used` does not exist —
  `RedeemCoupon` counts real `coupon_redemptions` rows instead
  (`reference/actions.md`) — and a redemption needs a real `order_id`
  (`NOT NULL`, cascade-on-delete). Orders are explicitly 0-scope for this
  seed, so faking one just to exhaust a coupon would fabricate order data
  nothing else in the set produces. `ONEUSEONLY` (`total_usage_limit: 1`,
  never redeemed) covers the narrow-limit path only; "reached" belongs to
  whichever session seeds orders.
- `App\Support\ResolveVariationMeasurements` — the weight/dimension
  counterpart to `ResolveVariationPrice`, and the fix for a semantic that
  was documented without being implemented: the new nullable
  `product_variations` measurement columns were specified as
  "null inherits the product's" with nothing anywhere performing that
  inheritance, so `$variation->weight_g` returned null for the common
  inheriting variation. A null weight reaching a courier is a zero-weight
  parcel rather than an error, which is why this is a resolver and not a
  convention.

  Inheritance is **per axis**, correcting an "all three or none" rule
  written into three docs a day earlier. That rule forbade the most
  realistic override there is — a hardcover edition is the same page size
  as its paperback sibling and only thicker — and buying nothing for it,
  since per-axis fallback is exactly what `price` already does. `isShippable()`
  is the one all-or-nothing check, because a courier quote needs the whole
  set even though each figure resolves on its own. 5 tests, all observed
  failing against a broken fallback first.
- `carriers.cod_fee` — BG couriers charge a separate cash-on-delivery
  handling fee, priced per carrier (Econt and Speedy differ), so it lives on
  `carriers` rather than `products` or `orders`. Not yet folded into an
  order total: `CreateOrder` hardcodes `shipping = '0.00'` until carrier
  selection (`CalculateDeliveryPrice`, slice 8) exists to read it from.
  `CarrierSeeder` seeds both at placeholder figures, not a published tariff.
- `App\Support\Money` — a readonly decimal value object, and the only place
  `bc*` is now called. Every money column is `decimal(n,2)`, so `SCALE` is
  internal and a caller never picks one: `bcadd($a, $b)` without a scale
  defaults to 0 and turns 189.90 into 189, which was reachable at any of the
  30 call sites this replaces across 6 files.

  Two methods carry the calculations whose precision is not obvious.
  `percentageOf()` is VAT extraction — prices are stored gross, so VAT is
  `amount * rate / (100 + rate)`, not `/ 100`, and getting it backwards
  overstates VAT on every line without failing. `shareOf()` is proportional
  allocation for splitting a discount across matched lines. Both run their
  intermediate at double scale and round once, because rounding each step
  compounds across a multi-line cart.

  Deliberately **not** currency-aware: `App\Enums\Currency` exists and orders
  and payments snapshot it, but the catalogue is single-currency and a
  currency field here would imply mixed-currency arithmetic is guarded when
  it is not. `reference/schema/open-schema-questions.md` #2 has what real
  multi-currency needs; the guard belongs here when it arrives.

  20 unit tests, no database. `CalculateCartTotals` (15) and
  `CalculateCouponDiscount` (17) verified unchanged after migration — the
  latter includes the proportional-allocation cases.
- `App\Filament\Concerns\ConvertsMeasurementInput`, shared by `CreateProduct`
  and `EditProduct`. `ProductForm` now asks for weight and the three
  dimensions in a chosen unit via `*_input` fields marked `dehydrated(false)`;
  this converts them to the canonical `weight_g` and `*_mm` columns on save.
  Shared rather than duplicated because a conversion that disagreed between
  the two pages would store different numbers for the same typed input.
- `product_variations.length_mm`/`width_mm`/`height_mm`, mirroring `price`'s
  existing null-inherits-the-product pattern: `null` on a variation means
  "same as the product", set only when a variation genuinely differs (a
  book's hardcover edition weighing more than its paperback sibling). No
  per-variation `dimension_display_unit` — a variation's dimensions are
  entered and shown in the *product's* display unit, never its own, since
  splitting the display convention across siblings buys nothing. Weight
  keeps its own per-variation `weight_display_unit`, because weight (unlike
  the display convention for dimensions) can genuinely differ enough between
  variations to warrant it.
- `product_variations.is_default` plus `App\Actions\Catalogue\SetDefaultVariation`,
  mirroring `SetMainProductImage`/`is_main` exactly: one `UPDATE ... SET
  is_default = (id = N)` statement, no lock needed since it's a blind write
  rather than a check-then-act. `AddProductVariation` auto-promotes a
  product's first variation to default the same way `AddProductImage`
  promotes a product's first image to main, and accepts `is_default: true`
  on a later variation to override it. `RemoveProductVariation` hands the
  flag to a live sibling if the removed variation held it, the same
  successor-promotion `RemoveProductImage` does for `is_main` — a product
  never ends up with variations and no default.
- `ProductVariationsRelationManager` — table gained an `is_default` badge
  column and a "Make default" row action (star icon, hidden once a
  variation is already default); the create/edit form gained weight and
  dimension inputs in the product's display unit, converted to canonical
  columns via `ConvertsMeasurementInput` before the Action runs.

### Removed

- `products.weight`, `products.dimensions`, `product_variations.weight` —
  the pre-structured free-text/decimal columns these superseded weeks ago,
  finally dropped now that every remaining reference to them (models,
  factories, fixtures) was confirmed migrated to `weight_g`/`*_mm`. Kept
  around "for one day" past the original migration pending that
  verification; the day came.

- The store is **Amazoff**. `APP_NAME` set in `.env` and `.env.example`;
  `logo.png`/`logo2.png` and `favicon.ico`/`favicon2.ico` swapped so the
  chosen pair is the one the app serves. `logo3.png` and `favicon3.ico` are
  untouched alternates.
- `App\Enums\Currency` (EUR, BGN) with `symbol()` and `minorUnitDigits()`.
  An enum rather than a lookup table, and not the roles exception: adding a
  currency needs a rounding rule, a symbol and a decimal count, all of which
  are code. `minorUnitDigits()` states the assumption that 2 is not universal
  — JPY is 0, and `decimal:2` arithmetic against a zero-decimal currency
  silently multiplies by 100. Nothing depends on it yet.
- `App\Enums\LengthUnit` and `App\Enums\WeightUnit`, with conversion in
  both directions, plus `2026_08_23_120000_add_structured_dimensions_and_weight`:
  `products.length_mm`/`width_mm`/`height_mm`/`dimension_unit`, and
  `weight_g`/`weight_unit` on both `products` and `product_variations`.
  Storage is canonical (whole millimetres, whole grams) with the entry unit
  stored beside it so the panel shows back what was typed; default cm and kg.

  Replaces free-text `dimensions` and `decimal(8,2)` kilogram `weight`.
  `open-schema-questions.md` #4 argued it: a courier prices on volumetric
  weight, needing 3 numbers, and parsing `"24 x 8 x 21 cm"` plus the
  `24x8x21cm` and `240 x 80 x 210 mm` variants a generator will produce is a
  bug found at API-call time rather than data entry. Verified: 5 g now stores
  as 5 g, where `decimal(8,2)` kg rounded it to 10 g.

  The superseded columns are **kept, not dropped** — dropping a merged column
  alongside its replacement leaves no way to verify a backfill. No backfill
  was needed (no production data, fixtures not yet authored), which is why
  this was cheap now. `open-schema-questions.md` #3 tracks the removal.
- `App\Actions\Cart\TouchCartExpiry` and `config/cart.php` — the missing
  half of `ExpireCarts`, which had nothing to act on because nothing ever
  wrote `carts.expires_at`. Guest carts expire 24 hours after the last write;
  a registered customer's cart never expires and has the column cleared.
  Called on every cart write so the window slides.

  `MergeGuestCart` now calls it inside its transaction, and that is the
  load-bearing case: without it a merged cart keeps the guest expiry and
  `carts:expire` deletes a registered customer's cart a day later.
- `docs/reference/schema/open-schema-questions.md` — deferred schema decisions, each
  with today's state, options with costs, a recommendation, and the trigger
  that would force revisiting. Linked from `CLAUDE.md`'s router.

- File upload validation on both image fields in the panel — product
  images (`ProductImagesRelationManager`) and article images (`ArticleForm`)
  — closing §37 standard 18's type and size half. `ProductImage` and
  `Article` each gained named constants (`ACCEPTED_MIME_TYPES`/
  `IMAGE_ACCEPTED_MIME_TYPES`, a JPEG/PNG/WebP allow-list; `MAX_SIZE_KB`;
  `MIN_WIDTH_PX`/`MIN_HEIGHT_PX`, a 400px floor against an accidental
  thumbnail upload) so both fields reference one source rather than
  duplicating literals. `->image()`'s own MIME sniff is unchanged;
  `->acceptedFileTypes()` narrows it from "any image" to the allow-list, and
  `Illuminate\Validation\Rules\Dimensions` enforces the floor — a
  validation rule, not a `FileUpload` method, applied through `->rules()`
  the same way price fields already apply `decimal:0,2`. `->imageEditor()`
  added to both fields too, optional crop/rotate for an admin who wants it.
  Storage method (§37 standard 18's third leg) was already met — both
  fields go through `ProductImage::DISK`/`Article::IMAGE_DIRECTORY`, named
  constants rather than literals, unchanged by this entry. Standard 19
  (unique generated filenames) stays open below — Filament's default
  upload-naming path was not traced to an actual stored filename, so it is
  recorded honestly as unverified rather than assumed.

  Caught by Larastan, not by review: the first version called
  `Dimensions::make()`, which does not exist on this Laravel version's
  `Dimensions` class — it has a plain constructor, no static factory.
  `php -l` and a first read both passed; `phpstan analyse --memory-limit=1G`
  did not, with `Call to an undefined static method
  Illuminate\Validation\Rules\Dimensions::make()` on both call sites.
  Fixed to `(new Dimensions())->minWidth(...)->minHeight(...)` and
  reconfirmed clean. Recorded in `troubleshooting.md`'s new entry as the
  general case: verify a fluent builder's actual API against the installed
  version before assuming a common Laravel idiom applies unchanged.
- `docs/how-to/troubleshooting.md` — two Windows/Docker-specific traps hit
  this session, in one entry: stopping a background test run through the
  harness kills the shell wrapper but not a child `pest` process it
  spawned, which then keeps racing every later command against the same
  MySQL container (`docker compose top app` is the reliable check;
  `docker compose exec app kill` doesn't exist on this image, `posix_kill`
  from a PHP one-liner does reach it); and Git Bash silently rewrites a
  bare `/tmp/...` argument to a Windows path before `docker compose exec`
  ever sees it, producing a Linux-shaped "No such file" error for a file
  that exists exactly where it should — `MSYS_NO_PATHCONV=1` is the fix.
  Both cost real time this session before the actual cause was found;
  two coverage runs and a Feature-suite run were re-run clean afterward to
  confirm neither had left a false result behind.
- `CLAUDE.md`'s "Working style" — when a test earns its place. Written
  after being asked whether the file-validation constraints above needed
  their own Pest coverage; they don't, and the rule states why: a test that
  reasserts Filament's or Laravel's own machinery works (does
  `->acceptedFileTypes()` reject a bad MIME type, does `Dimensions` reject
  a too-small image) proves the framework, which is already proven
  upstream, for a flaky, fixture-heavy cost. `ProductResourceTest.php`'s
  `'refuses a product with no variations'` already draws the line
  correctly — it uses Filament's own form-testing machinery, but what it
  proves is `ProductRequiresVariationException`'s territory, a domain rule.
  Mirrored into `.ai/guidelines/project-conventions.md`'s Testing section
  per this file's own rule to keep the two in agreement.

- Variation image galleries — `product_image_product_variation`, a
  many-to-many between a variation and its product's own `product_images`,
  with a `position` column and a composite primary key over the pair. A
  variation shows some of the product's photographs in an order it chooses;
  one photograph can sit at a different position in several galleries, so a
  product shot in five colours across eight sizes is thirty image rows rather
  than two hundred and forty. `product_images` is untouched — still
  product-owned, `product_id` still NOT NULL, the whole
  `AddProductImage`/`SetMainProductImage`/`RemoveProductImage` triad and its
  one-main invariant unchanged. ADR-0013 has the alternatives, including the
  attribute-value swatch model that would have been better on write and worse
  on read.
- `app/Actions/Catalogue/SetVariationImages.php` — 1 Action owning the whole
  ordered set, rather than an attach/detach/reorder triad. The contested state
  is the ordered list, so it gets one owner, the way `SetMainProductImage`
  owns "exactly one main image" for a product. Three anticipated races collapse
  into one: adding while another administrator removes, reordering while
  another detaches, and two simultaneous reorders are the same operation —
  two complete sets, serialised by the `products` lock, the later winning
  wholesale. Position renumbering disappears with them, because nothing ever
  writes a partial set. Verified: order preserved, duplicates collapsed to
  their first position, empty list clears, a cross-product image refused with
  `ImageNotOnProductException`, a variation soft-deleted since page load
  refused with `RemovedFromCatalogueException`.
- `app/Support/ResolveVariationImage.php` — gallery position 1, else the
  product's main image, else null. Computed, never stored, the same shape as
  `ResolveVariationPrice`.
- `tests/Concurrency/SetVariationImagesConcurrencyTest.php`, plus
  `set-variation-images` and `remove-image` arms on `race:worker` and an
  `idsFrom()` accessor for variable-length id lists. Deletion-proofed and it
  actually went red: removing the `products` lock fails the atomicity
  assertion on 4 of 4 attempts, with the surviving gallery containing rows
  from *both* submissions — `sync()` issues its detach and its attaches as
  separate statements, and two transactions interleave between them. Worth
  recording next to `DeleteProductCategoryConcurrencyTest`, where fourteen
  attempts never forced the equivalent failure; this window is wide enough to
  hit every time.
- A gallery modal on `ProductVariationsRelationManager`, as a row action
  rather than the nested relation manager the draft plan assumed — Filament
  relation managers do not nest. It happens to fit the Action: the modal
  submits the whole set, and the multi-select's selection order becomes
  `position`.
- `docs/adr/0013-variation-image-ownership.md`,
  `docs/reference/write-rules/product-variation-images.md`, and
  `docs/explanation/product-variability.md`. The last also records which
  large-catalogue techniques this schema already uses, which one is worth
  designing toward (a flattened listing projection, so Blade never joins 5
  tables per row), and which two are deliberately not done.

- `app/Actions/Content/PublishArticle.php`, closing §37 criterion 17. Moves
  an article through §22's lifecycle — draft, scheduled, published, archived
  — checking `ArticleStatus::canTransitionTo()` before the write.
  `publish_article` is a permission separate from `update_article`
  (`content_editor` holds both, but a `Select` on `status` would have
  checked `update` and skipped the matrix), so this is an Action below
  ADR-0007's usual multi-table bar, built anyway because the authorization
  is the entire point of the operation. `published_at` is stamped the first
  time an article reaches Published and never rewritten — it answers "when
  did readers first see this," which an unpublish-and-republish does not
  change. Verified: `content_editor` can transition, `warehouse_employee`
  is refused with `AuthorizationException`, an illegal move throws
  `ArticleTransitionNotAllowedException`, and `published_at` survives a
  Published → Draft → Published round trip unchanged.
- Filament resource over `Article`, full CRUD — the panel's first, since
  every prior resource this session was either lookup-table CRUD or
  deliberately read-only. `author_id` is NOT NULL and never a form field;
  `CreateArticle::mutateFormDataBeforeCreate()` sets it from `auth()->id()`.
  No `mutateFormDataBeforeSave()` on the edit page — that would reassign
  authorship to whoever last touched the record, which `updated_at` already
  answers. `status` and `published_at` are absent from the form entirely;
  both are `PublishArticle`'s alone.
- The status-change menu on `ArticlesTable` is generated from
  `ArticleStatus::cases()` rather than hand-written, one button per case.
  `visible()` calls the same `canTransitionTo()` the Action enforces, so
  the menu can only ever offer legal moves and the matrix stays the single
  place the rule lives — widening the enum widens the menu with no second
  edit. The Action still re-checks on click; a hidden button is UX, not the
  guarantee.
- `Article::content` uses `RichEditor`, the panel's first rich-text field
  (§22 — headings, lists, links, images, quotes, tables, embedded video,
  code blocks). Sanitising it is deliberately **not** done here: `CLAUDE.md`
  places Purify at render time, and nothing renders an article yet — a
  write-time cast would be a second, earlier answer to a question
  render-time already owns.
- `app/Exceptions/ArticleTransitionNotAllowedException.php` — carries both
  ends of the refused move as `ArticleStatus` instances rather than
  strings, so a catcher can build its own message from `getLabel()`.
- `@property ArticleStatus $status` on `Article`. Without it Larastan
  inferred the raw `enum()` literal union instead of the cast, rejecting
  `$article->status->canTransitionTo($to)` as "cannot call method on
  string" even though the runtime type is correct — proven with
  `PHPStan\dumpType()`, which is also how the fix (mirroring `Coupon`'s
  existing `@property CouponType $type`) was found rather than guessed.
  `Order::$status` has the identical gap, uncaught until whoever writes
  against it hits the same error.
- `brianium/paratest` as a dev dependency, plus a wildcard grant in
  `docker/mysql/init/01-test-database.sh` for the per-worker databases
  Laravel creates. `pest --parallel --processes=4 --testsuite=Feature` runs
  472 tests in 260s against 474s sequential. Two measured findings recorded
  in `run-the-tests.md`: `--processes=12` (this machine's `nproc`) is
  *slower* at 338–361s, because every worker re-runs `migrate:fresh`
  including the ~55s schema load and twelve of them contend on one MySQL
  container; and `Concurrency` must never run under `--parallel` — 21 of 33
  tests fail, since Laravel only switches a test case onto its own per-worker
  database when it uses `RefreshDatabase` or a sibling trait, which
  `tests/Pest.php` deliberately does not apply there.
- `app/Actions/Cart/ExpireCarts.php` and `app/Console/Commands/ExpireCarts.php`
  — deletes carts past `expires_at`, excluding any already referenced by
  `orders.cart_id`, scheduled `->daily()` in `routes/console.php`. The first
  scheduled command in the project. Inert today by design: nothing in `app/`
  writes `expires_at` yet, because the TTL policy (guest carts expire after
  about a month; a registered customer's cart does not expire — the checkout
  stage is what expires for them) is not built.
- `docs/reference/console-commands.md` — every custom Artisan command, what
  invokes it, and why a command is a caller rather than a place a rule lives.
  Also records that nothing runs `schedule:run` locally, so a scheduled
  command never fires on its own in Docker.
- `app/Console/Commands/RaceWorker.php` and
  `tests/Concurrency/RaceHelper.php` — one `race:worker` Artisan command
  replaces the PHP nowdoc every concurrency test used to write to
  `base_path()`, spawn, and `@unlink()`. All twelve race files now describe
  a race as a job list (`action`, `ids`, `args`, optional `rendezvous`) and
  call `runRaceWorkers()`. `concurrency-and-locking.md`'s "Why the worker is
  generated, not committed" had already recorded the duplicated bootstrap as
  a cost and named this refactor as an agreed follow-up; this is it. Roughly
  700 lines of duplicated boilerplate removed, and the suite got faster as a
  side effect —
  518s against a ~695s baseline, because Artisan's bootstrap beats a
  hand-rolled `require bootstrap/app.php` per worker. Behaviour preserved:
  all 33 tests pass, and `ReserveStockConcurrencyTest` was re-deletion-proofed
  against the new harness (lock removed, `QueryException` instead of the
  clean refusal, exactly as before). The rendezvous ready-flags moved to
  `storage/framework/testing/`, which Laravel already gitignores — a worker
  killed between planting its flag and unlinking it used to leave an
  untracked file in the project root.
- `tests/Pest.php`: `Feature` now uses `LazilyRefreshDatabase` instead of
  `RefreshDatabase` — same isolation guarantee, migrates only on first DB
  touch. Measured back to back on this suite: 683s → 617s, 479/479 tests
  unchanged. Verified structurally, not just measured: `LazilyRefreshDatabase`
  `use`s `RefreshDatabase` internally, so `class_uses_recursive()` still
  resolves it for Laravel's per-worker test-database switching —
  `pest --parallel --testsuite=Feature` is unaffected.
- `docs/adr/0012-laravel-boost.md` and `laravel/boost` (dev-only) — MCP
  server (schema/query/log tools plus semantic search over
  Laravel/Filament/Pest docs), guidelines, and skills for AI-assisted
  development. `online-store/.ai/guidelines/project-conventions.md` is the
  hand-written, committed source; `online-store/CLAUDE.md`, `boost.json`,
  and `.claude/skills/` are generated and gitignored, rebuilt by
  `php artisan boost:install`. `.mcp.json` points the MCP entry through
  `docker compose exec app`, since this project's `vendor/` only exists
  inside the container. Root `CLAUDE.md` now says explicitly that it is
  hand-written and points at the generated file rather than being confused
  for it.

  ADR-0012 audits Boost's bundled guidance against this project's actual
  architecture rather than accepting it wholesale. Three real conflicts,
  all overridden in `.ai/guidelines/project-conventions.md`: "only create
  documentation files if requested" (this project's docs are part of the
  work, not an extra); "always use constructor injection, avoid `app()`"
  (every Action in this codebase is resolved at the call site, by design —
  checked against all 30+ existing Actions, not assumed); "code payment
  gateways to an interface" (ADR-0001 decided the opposite, by name: two
  courier implementations justify `App\Contracts`, one Stripe
  implementation does not). Also records, with source-level evidence, why
  `Illuminate\Concurrency\ProcessDriver` doesn't replace `race:worker` —
  no barrier, no rendezvous, and no exposed way to pass per-task `DB_*`
  environment at all.

  One real, applied finding from the audit beyond the ADR itself:
  `carts:expire`'s schedule entry gained `->withoutOverlapping()`, per a
  Boost scheduling rule naming a genuine gap in what was already there.
- `app/Actions/Catalogue/DeleteProductCategory.php` — the one Action
  `ProductCategory` needed despite CLAUDE.md's plain-lookup-table exemption.
  `ProductCategoryPolicy::delete()`'s own docblock had already named the
  gap: whether a category could be deleted at all lived in a Filament
  `visible()`/`disabled()` closure, read once when the row rendered, with
  its `children()->count()` half commented out because `ProductCategory`
  had no `children()` relation. Added the relation, the Action
  (`ProductCategoryCannotBeDeletedException`, `lockForUpdate()` on the
  category before counting live children and products), and moved the
  guarded delete from the list table's row action to `EditProductCategory`'s
  header, matching `EditProduct`'s existing shape — a static Table class has
  no `$this` for `ReportsDomainFailures` to bind to, a Page does.
- `tests/Concurrency/DeleteProductCategoryConcurrencyTest.php` — deleting a
  category while a subcategory is created underneath it. Asymmetric
  deliberately, and says so: the create side is plain Eloquent, so its
  failure mode is a raw `QueryException` off `parent_id`'s foreign key, not
  a domain exception. Measured, not assumed: without a second, tighter
  rendezvous on top of the usual wall-clock barrier, the create side won
  every time — boot jitter alone was deciding it, the trap
  `AddToCartVsMergeGuestCartConcurrencyTest` already names for a different
  pairing. With the rendezvous both sides win a real share. Also recorded
  honestly: deletion-proofing the Action's lock across fourteen attempts
  never forced the one failure that would prove it necessary — the window
  is narrower than this harness can reliably hit, the same conclusion
  `write-rules/order.md` already reached for the deadlock-prevention sort.
  The lock stays regardless, on the same reasoning `ReserveStock`'s does.
- `docs/reference/write-rules/product-category.md` — the outcomes page for
  the above.
- `misc/variation-images-plan.md` — draft plan for giving product variations
  their own optional image gallery, additive to the existing product-level
  one. Two schema-shaping questions left open for explicit sign-off before
  any migration is written.
- Docker Compose local dev environment: `app` (PHP-FPM), `webserver`
  (nginx), `db` (MySQL 8), `vite`, `mailpit`.
- Filament admin panel, installed and verified against Laravel 13.8.
- `spatie/laravel-permission` for roles. `User` uses `HasRoles`;
  `canAccessPanel()` gates the Filament panel on `User::STAFF_ROLES`.
  Verified: staff reach the panel, customers do not, multi-role works.
- `spatie/laravel-activitylog` and `astrotomic/laravel-translatable`
  installed — not wired in yet, decisions behind them still open.
- Saloon, Stripe SDK, Purify, Larastan, Pest, `laravel-lang/common` added
  to `composer.json`.
- GitHub Actions CI workflow: Pint, Larastan, Pest against a real MySQL
  service container, triggered on push/PR to `main`.
- `docs/` restructured around Diátaxis, plus `adr/` and `changelog/`.
- `docs/adr/0001-tech-stack-selection.md` — combined ADR for kickoff-phase
  tech choices.
- `docs/explanation/documentation-design.md` — how `docs/` is organized and
  the ADR vs explanation split.
- `docs/explanation/gdpr.md` — soft vs hard delete, order anonymization,
  hashed coupon redemption identifiers.
- `docs/how-to/regenerate-with-blueprint.md` — safe regeneration procedure,
  the two hand-written files, and the generated code that needs correcting.
- `docs/how-to/use-ci.md` — what the workflow runs, how to reproduce a
  failure locally, and what a green check does not cover.
- `CLAUDE.md`, `CONTRIBUTING.md`, `CONTRIBUTIONS.md`,
  `.github/PULL_REQUEST_TEMPLATE.md` at repo root.
- Schema generated from `draft.yaml`: 39 migrations, 32 models, 32
  factories. `migrate:fresh` applies cleanly; every factory persists a row.
- `online-store/stubs/blueprint/` — overrides `model.fillable.stub` and
  `model.hidden.stub` to emit `@var list<string>`, which Larastan requires.
- `App\Enums` — 12 backed enums covering all 19 `enum` columns in the schema:
  `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `ShipmentStatus`,
  `InventoryMovementType`, `ArticleStatus`, `CouponType`, `CouponScope`,
  `AddressType`, `DeliveryType`, `AttributeInputType`, `NewsletterStatus`.
  Each carries `values()` and implements Filament's `HasLabel`, so tables,
  filters, and select fields render them without a per-resource value map.
- `HasColor` on the 7 enums where a badge colour carries meaning —
  `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `ShipmentStatus`,
  `InventoryMovementType`, `ArticleStatus`, `NewsletterStatus`. The remaining
  five classify rather than describe state, where a colour would be decoration.
- `allowedTransitions()` and `canTransitionTo()` on the four lifecycle enums —
  `OrderStatus`, `PaymentStatus`, `ShipmentStatus`, `ArticleStatus`. Which
  enums get a matrix, why the other eight do not, and where the policy and
  enforcement halves belong are in ADR-0004. Nothing calls these yet.
- Enum casts on the 12 affected models, so the enums are authoritative in
  application code rather than decorative. Verified against MySQL: a status
  written as an enum case reads back as one.
- Factories use `Enum::cases()` in place of the literal value arrays Blueprint
  generated. A renamed case now fails at parse rather than on insert.
- `tests/Feature/FactoryTest.php` — persists one row from every factory,
  discovered by glob. This is the check `docs/how-to/regenerate-with-blueprint.md`
  describes as the only way to catch a factory writing a value its column
  cannot hold; it was documented but not automated.
- Six Filament resources over the catalogue's lookup entities: `Brand`,
  `Tag`, `ProductCategory`, `ArticleCategory`, `Attribute`, `AttributeValue`.
  Scaffolded with `make:filament-resource --generate`, then corrected by hand
  — see Fixed, below. `ProductCategory`'s self-referencing `parent_id` and
  `AttributeValue`'s `attribute_id` foreign key both resolved to `Select`
  fields backed by `relationship()` without manual intervention.
- `database/seeders/RoleSeeder.php` — creates the three `User::STAFF_ROLES`
  rows (`administrator`, `content_editor`, `warehouse_employee`). Runs in
  every environment, production included, since a role must exist before
  anyone can be assigned to it through the panel.
- `DatabaseSeeder` now creates a staff account and assigns it the
  `administrator` role, gated behind `! app()->isProduction()` — reference
  data (roles) seeds everywhere, a known-password test credential does not.
  Closes the gap the `Open` section below used to track.
- `User` implements `Filament\Models\Contracts\HasName`, alongside the
  existing `FilamentUser`. See Fixed, below, for why this was load-bearing
  rather than cosmetic.
- `docker/mysql/init/01-test-database.sh`, mounted into the `db` service's
  `docker-entrypoint-initdb.d/`. Creates `online_shop_test` and grants the
  app user access to it on first container initialization, matching what
  CI's MySQL service already provisions — local `pest` runs against a fresh
  clone without a manual `CREATE DATABASE` step.
- `database/seeders/PermissionSeeder.php` — 104 permissions named
  `{ability}_{resource}`, the ability half matching the Laravel policy method
  that checks it. Seeded in full rather than per built resource: §3.3 and
  §3.4 describe what a role may do, not what happens to be built, and
  `content_editor`'s deny-list is only meaningful if the permissions it
  excludes exist.
- `RoleSeeder` now attaches those permissions — 20 to `content_editor`, 12 to
  `warehouse_employee`, none to `administrator`. `syncPermissions()` rather
  than `givePermissionTo()`, so a permission removed from the seeder is
  actually revoked on the next run; the tradeoff is that a re-seed discards
  runtime edits made through the panel.
- `database/seeders/UserSeeder.php` — one account per role plus a plain
  customer, gated to non-production. §37 criterion 18 is only demonstrable
  with an account per role, and the customer is what proves
  `canAccessPanel()` denies someone holding no role at all.
- `Gate::before` in `AppServiceProvider` grants `administrator` every
  ability. Returns `null` rather than `false` when the role is absent, so
  other users still reach spatie's callback and then their policy.
- 20 Policy classes — one per resource the permission catalogue names,
  not one per Filament Resource built, since a missing policy fails open the
  moment a resource is scaffolded. Most methods are one
  `$user->can('{ability}_{resource}')`, checking permissions rather than role
  names because §3.5 requires permissions editable at runtime. `Order` and
  `Payment` refuse creation outright; `Order`, `ProductReview`, and `User`
  add ownership branches; `User` refuses self-deletion. `RolePolicy` is
  registered by hand in `AppServiceProvider` because spatie's `Role` sits
  outside `App\Models` and convention does not find it.
- `tests/Feature/RolePermissionTest.php` — 32 tests over the §37 criterion 18
  matrix, weighted toward the denials, including that every model with a
  Resource resolves a policy at all.
- `docs/adr/0006-authorization-layers.md` — why panel access, permissions,
  policies, and the administrator exemption are four separate mechanisms, and
  what the arrangement costs.
- `docs/how-to/run-the-tests.md` — running one file or one test, the flags
  worth knowing, why the suite needs MySQL, and how to check that a test can
  actually fail.
- Filament resource over spatie's `Role`, satisfying §3.5 — permissions
  editable without a deploy, which until now described an arrangement nobody
  could exercise. Edit only: no create or delete, since `canAccessPanel()`
  gates on the `User::STAFF_ROLES` constant and a role created in the UI
  would grant no panel access until that constant changed. Permissions render
  as one checkbox list per resource, each scoped to its own names so several
  lists can edit the same relation without clearing each other.
- `App\Support\PermissionCatalogue` — the catalogue's shape, read by both
  `PermissionSeeder` and the roles form. Previously private constants on the
  seeder; the UI needed the same groupings.
- `docs/reference/permissions.md` — the 104 permissions, the three roles and
  what each holds, and which check answers which question.
- `docs/how-to/edit-a-role.md` — the panel path and the seeder path, why they
  are not equivalent, and what the screen deliberately refuses to do.
- `app/Actions/Inventory/` — `RecordInventoryMovement`, `ReserveStock`, and
  `ReleaseStock`, the first Actions in the codebase, plus
  `Inventory::available()` and `InsufficientStockException`. Both writing
  Actions take `DB::transaction` and `lockForUpdate`; the ledger writer
  deliberately opens no transaction, since a movement without the quantity
  change it describes is a lie and the caller owns the boundary.
- `tests/Concurrency/`, a testsuite of its own, because `RefreshDatabase`
  rolls back rather than commits and a second connection cannot see rows that
  were never committed. The race test runs two OS processes against a shared
  wall-clock barrier and asserts the *type* of the loser's exception — both
  the locked and unlocked versions produce one winner, and only the locked one
  fails cleanly.
- `docs/adr/0007-action-conventions.md` — the actor is a nullable last
  parameter and null means the system; events dispatch after commit; an Action
  is required where a rule spans tables rather than everywhere; composition
  nests via savepoints.
- `docs/explanation/inventory.md` and
  `docs/explanation/concurrency-and-locking.md` — the stock counters and the
  locking that protects them, including why `increment()` rather than
  arithmetic in PHP is load-bearing: the constraint catches the first case as
  a 500 and cannot catch the second at all.
- `docs/how-to/start-a-session.md` — a session prompt for Claude Code, with
  the reasoning for each instruction so it can be edited rather than copied
  once and left to go stale.
- Filament resource over `Product`, with `ProductVariation`, `ProductImage`,
  and `ProductSpecification` as relation managers rather than resources of
  their own. None is browsed independently of its product, and a standalone
  resource would let a variation be created without one. Relation managers
  only render on the Edit page — a child row needs its parent's id, which
  does not exist while the create form is open.
- `ProductImagePolicy` and `ProductSpecificationPolicy`, checking
  `view_product` and `update_product` rather than permissions of their own.
  Managing a product's images or specifications is editing that product, and
  §3 describes no role that draws a line between them. A distinct class is
  still required: `ProductPolicy::update()` is type-hinted to `Product` and
  cannot receive a `ProductImage`. `ProductVariation` keeps its own
  permission set, because §3.4 gives the warehouse a reason to read SKU and
  stock without holding the catalogue's descriptive content.
- Filament resource over `Coupon`, satisfying §11's discount codes. Fields
  react to each other: `value` renders as `%` or `EUR` and caps at 100 or the
  column ceiling depending on `type`, `max_discount_amount` appears only for
  percentage coupons, and the product and category pickers appear only under
  the matching `scope`. `times_used` is displayed but never submitted —
  `disabled()` plus `dehydrated(false)`, since an editable counter would let
  an exhausted coupon be reopened by typing a smaller number.
- Table filters, the first in any resource: `type`, `scope`, and `is_active`
  on coupons.
- Filament resources over `ContactMessage` and `NewsletterSubscriber`, the
  first read-mostly ones: no create page, no create action, and a View page
  with an infolist — also the first infolists in the panel. Both arrive from
  public forms (§5, §26), so creating one by hand would fabricate a record
  the sender never submitted.
- `contact_messages.handled_at` and `internal_note`, in a new migration.
  `ContactMessagePolicy::update()` already described "marking handled or
  attaching an internal note", but the columns it assumed did not exist, so
  the edit screen's only effect was rewriting the sender's own words. The
  customer's fields are now `disabled()` and `dehydrated(false)`; only the
  two staff columns are writable. `handled_at` is a nullable timestamp
  rather than a boolean — when a message was dealt with is worth more than
  that it was, and null already means outstanding.
- Filament resource over `Order`, read-only, with `orderItems`,
  `orderStatusHistories`, and `orderAddresses` as read-only relation
  managers. `OrderPolicy` refuses create (§12 — an order exists because
  checkout ran) and delete (§19 — the history has to survive), and there is
  no edit page because the only legitimate write is a status change, which
  belongs in `TransitionOrderStatus`. **Criterion 16 is therefore not met
  yet**: the screens read orders, nothing moves one. Order items are the §18
  price snapshot and status history is the §19 audit trail, so neither is
  hand-editable by design.
- `OrderAddressesRelationManager` composes one readable address line rather
  than listing six columns, branching on `DeliveryType` — a home delivery
  fills `street`, a courier pickup fills `courier_office_*`, never both.

- `docs/adr/0009-code-coverage.md` — PCOV for `pest --coverage`, chosen over
  Xdebug by benchmarking both against this suite specifically (+13% on
  `tests/Concurrency/`, +36% on a fast in-process run) rather than assuming
  the difference. Reported as a CI artifact, never gated — no `--min`
  threshold, consistent with this project's existing position that a
  passing test is not evidence without deletion-proof.
  `docker/php/conf.d/pcov.ini`, `docker/php/conf.d/cli-memory.ini` (the
  default 128M `memory_limit` cannot assemble a full-project report).
- `docs/reference/testing/coverage.md` — per-class PCOV breakdown, distinguishing
  lines proven by a concurrency test PCOV cannot see from lines genuinely
  untested.
- `docs/reference/write-rules/` — `product-write-rules.md`,
  `cart-write-rules.md`, and `concurrency-coverage.md` moved here as
  `product.md`, `cart.md`, `concurrency.md`. The three cross-reference each
  other constantly and shared a naming pattern already; ~25 other files
  citing the old paths updated.
- `tests/Concurrency/ReleaseStockConcurrencyTest.php`,
  `ForceDeleteProductVariationConcurrencyTest.php`,
  `MergeGuestCartConcurrencyTest.php` — three mechanisms
  (`lockForUpdate()`, a catch-and-retry) that existed in application code
  but had never been raced by two real processes. All three verified by
  deletion.
- `tests/Concurrency/AddToCartVsMergeGuestCartConcurrencyTest.php` — races
  `AddToCart` against `MergeGuestCart` directly rather than each against
  itself. Proved the collision is real and `AddToCart`'s retry handles it;
  could not prove `MergeGuestCart`'s retry in this specific pairing across
  24 attempts under three synchronization strategies — `MergeGuestCart` has
  no domain validation before its insert and wins every time in this
  environment. Recorded as a measured, narrower gap rather than claimed as
  fully verified. `docs/explanation/concurrency-and-locking.md` gained a
  section on why a cross-Action race needs a rendezvous beyond the usual
  wall-clock barrier.
- Tests closing three branches no test exercised: `ForceDeleteProduct`'s
  refusal when a product has reviews, `RemoveProductImage`'s authorization
  check (nothing had ever called it with a non-null actor), `ReleaseStock`'s
  `quantity < 1` guard (present and tested on `ReserveStock`, missing on its
  sibling).
- `app/Actions/Order/TransitionOrderStatus.php` — the only writer of
  `orders.status`, closing the gap ADR-0004 designed and left unbuilt. Checks
  `OrderStatus::canTransitionTo()` for legality, `OrderPolicy::updateStatus()`
  for authorization (routed by target status — `cancel_order`/`refund_order`
  for `Cancelled`/`Refunded`, `updateStatus_order` otherwise), writes the §19
  history row, applies the target status's inventory effect, and dispatches
  `OrderStatusChanged` after commit. `$from === $to` is a clean no-op rather
  than a refusal, checked after authorization so a denied actor cannot infer
  a move's legality from a silent success. Locks `orders`, re-reading
  `status` from the locked row rather than the `Order` instance passed into
  `handle()` — the one subtlety that compiles either way and only a
  two-process test can tell apart; see
  `explanation/concurrency-and-locking.md`, "Trusting the locked row, not the
  reference that was locked."
- `app/Actions/Inventory/CompleteSale.php` and `RestockReturn.php` — the two
  movement directions §20's schema always had (`sold_quantity`,
  `returned_quantity`, `InventoryMovementType::CompletedSale`/
  `CustomerReturn`) and nothing wrote to before now. Composed by
  `TransitionOrderStatus` on `=> Shipped` and `=> Returned`; ADR-0011 records
  why they live inside that Action rather than behind a `CancelOrder`/
  `ShipOrder` wrapper. `CompleteSale` decrements `reserved_quantity` before
  `current_quantity` — the reverse order can violate
  `chk_inventories_reserved_not_above_current` mid-transaction when a sale
  empties fully-reserved stock, and nothing static catches the ordering;
  `CompleteSaleTest`, "completes a sale that empties the entire
  reserved-equals-current stock" is deletion-proofed against it.
- `App\Exceptions\IllegalOrderStatusTransitionException` and
  `App\Events\OrderStatusChanged` — the first class in `app/Events`, so the
  storefront/panel/webhook has a convention for `ShouldDispatchAfterCommit`
  (ADR-0007) to follow rather than inventing one under time pressure. No
  listeners yet; §28's queued emails are a later slice.
- `cancel_order` and `refund_order` permissions, in
  `PermissionCatalogue::DOMAIN_ABILITIES`. Catalogue grows 104 → 106.
  `warehouse_employee`'s seeded grant list is unchanged — it holds neither,
  by design, relying on `Gate::before` to grant an administrator both rather
  than an explicit row.
- A migration adding `UNIQUE(order_id, new_status)` to
  `order_status_histories` — the backstop for `TransitionOrderStatus`'s
  `orders` lock, in the same relationship
  `chk_inventories_reserved_not_above_current` has to `ReserveStock`. Depends
  on `OrderStatus`'s transition graph being acyclic, which
  `tests/Unit/Enums/TransitionMatrixTest.php` now asserts algorithmically
  (a DFS cycle check) rather than only by the file's existing hand-written
  legal/illegal table, which — by the file's own stated reasoning — could be
  edited in step with a matrix change that introduced a cycle and still pass.
- `docs/adr/0011-order-status-side-effects.md` — the inventory consequence of
  a status transition lives inside `TransitionOrderStatus`, keyed by target
  status, rather than in a `CancelOrder`/`ShipOrder` wrapper; records the
  explicit departure from ADR-0004's original illustrative example and why.
- `tests/Concurrency/TransitionOrderStatusConcurrencyTest.php` — two staff
  transitioning 1 order at once, closing the gap
  `reference/write-rules/concurrency.md` had listed as "Not covered." Two
  races, not one: identical concurrent transitions (both succeed, exactly one
  write — the no-op design means this is not a winner/loser shape at all,
  see `explanation/concurrency-and-locking.md`, "A fourth shape: idempotent
  no-ops") and two different, mutually-exclusive transitions from the same
  origin (ordinary winner/loser). The second race deliberately avoids
  `Cancelled` as either side — it is reachable from almost every status, so
  a pairing including it is not reliably exclusive.
- Closed three of `write-rules/order.md`'s five original `CreateOrder`
  "Known gaps," all the same idempotency shape (a constraint plus a caught
  violation, never check-then-act):
  - `orders.cart_id` (`UNIQUE`, nullable, no foreign key) plus
    `CartAlreadyCheckedOutException` — the same cart checked out twice, once
    a deliberately-pinned gap with its own concurrency test, now a
    guarantee. `CreateOrderConcurrencyTest`'s third race rewritten from
    asserting 2 orders to asserting 1 order and a clean refusal for the
    loser.
  - `CheckoutActorRemovedException`, a caught `QueryException` on the
    `orders_user_id_foreign` constraint — a hard-deleted actor between being
    read and the `orders` insert now refuses cleanly instead of surfacing a
    raw `QueryException`. `CreateOrderTest`'s gap-pinning test rewritten to
    assert the new exception.
  - `CouponNotApplicableException::noLongerExists()` — a coupon whose row is
    gone by checkout now refuses rather than silently proceeding at full
    price. Discovered while writing its test that this is defence in depth
    rather than a reachable gap: `carts.coupon_id`'s own foreign key has no
    cascade, so an ordinary `$coupon->forceDelete()` while any cart still
    applies it already fails at the database with error 1451 — the test
    constructs the state by disabling FK checks around the delete, the
    technique the concurrency suites use for truncation, precisely because
    the ordinary path is already closed. `coupon` is now nullable on the
    exception, since this one refusal has no row left to carry.
  - The remaining two gaps (the deadlock-prevention sort's evidence gap,
    `shipping_amount` hardcoded pending courier integration) are unchanged —
    neither is a mechanical correction.
- `app/Actions/Inventory/RecordDamage.php` — the last of §20's ledger
  directions nothing wrote to, closing the status-transition "Known gaps"
  entry on damaged returns halfway: `current_quantity` to
  `damaged_quantity`, general-purpose like `ReserveStock`/`ReleaseStock`
  rather than composed by `TransitionOrderStatus`. Guards `available()`
  (current minus reserved), not `current_quantity` alone — damaging reserved
  stock would push `reserved_quantity` above `current_quantity`, the same
  `CHECK` constraint `CompleteSale`'s decrement ordering already respects.
  No caller composes it and no admin surface triggers it yet; a damaged
  return is still `RestockReturn` followed by a separate manual
  `RecordDamage` call, not a single automatic path.

### Changed

- `weight_unit` -> `weight_display_unit` and `dimension_unit` ->
  `dimension_display_unit`. The old names claimed something false: `weight_g`
  is **always** grams, and `weight_unit` beside it reads as "the unit this
  value is in", which would make `weight_g = 1600, weight_unit = kg` mean
  1600 kg. It means 1600 grams, displayed as 1.6 kg. Storage is canonical,
  display is remembered, and the names now say so. Free to rename: nothing
  outside the model declarations read either column.
- `ProductForm` no longer edits the superseded `weight` decimal or the
  free-text `dimensions`. Those columns still exist —
  `reference/schema/open-schema-questions.md` #3 tracks their removal — but
  the panel and the fixture format now agree on which columns matter, which
  they did not for the few hours between the two changes.
- `products.is_available` is confirmed **not** a derived OR of its variations,
  and `write-rules/product.md` now says so. A product may be available while
  every variation is not: §6 gives the merchandiser a switch, and
  visible-but-unbuyable is how a shop signals "coming back". The consequence
  a storefront query must carry is recorded there — an "in stock" filter needs
  the product flag **and** a `whereHas` on variation availability, because the
  flag alone shows products with nothing to buy.
- Review eligibility needed no change: `CreateProductReview` already requires
  a delivered order line for the product, `ProductReviewPolicy::create()`
  returns false outright, and `ProductReviewResource` has no create page — so
  the Action is the only path and it already enforces §24. Recorded rather
  than re-implemented.
- **Dropped `product_variations.image_id`**, the single optional pointer at one
  of the product's images that the gallery replaces. Two columns answering one
  question forces a resolver to invent a precedence rule nothing enforces on
  write. Removed in a new migration, per the append-only rule.

  Three things went with it, each a simplification. `RemoveProductImage`'s
  in-use guard and `ProductImageInUseException`: they converted one specific
  error 1451 into an actionable message, and that 1451 is now unreachable —
  the only remaining reference to `product_images` from outside its own
  product is the pivot, which cascades. Removing an image now takes it out of
  every gallery it was in rather than being refused. `ForceDeleteProduct`'s
  ordering constraint between variations and images, now only a comment. And
  `ProductVariationFactory`'s `image_id`, which called `ProductImage::factory()`
  and therefore attached an image belonging to a *second, unrelated product* to
  every variation it built — `tests/Pest.php` already worked around it with an
  explicit `image_id => null` and a comment saying exactly that. The pivot
  cannot express that mistake; `SetVariationImages` refuses it by name.

  One test was deleted rather than rewritten: "keeps the file when the removal
  is refused", which proved `RemoveProductImage` leaves the file on disk when
  it refuses. The Action has no refusal left, so nothing can reach the branch.
  The property is still real and still commented in the Action; what is gone
  is a way to exercise it.
- The gallery's ordering column is `position`, not `sort_order`, though
  `product_images` already has a `sort_order` — because it already has one.
  The first test written against the pivot failed with `SQLSTATE[23000] ...
  Column 'sort_order' in field list is ambiguous` on a query joining both.
  Renamed rather than qualified at every call site; the migration docblock
  records the failure.

- CI split into three parallel jobs (ADR-0010): `lint` (Pint, Larastan, no
  database), `test` (`tests/Unit` + `tests/Feature`, 2-shard matrix), and
  `test-concurrency` (`tests/Concurrency`, 3-shard matrix). Both suites'
  shards are hand-partitioned by measured wall-clock time, not split
  evenly by file count — each suite has one file whose cost would
  otherwise land wherever alphabetical order put it: one
  `tests/Concurrency` file using `->repeat(6)` is over half that suite's
  time; `tests/Feature/RolePermissionTest.php`'s `beforeEach` reseeds
  three seeders before every test (deliberate — the permission registrar
  caches for 24h) and is over a third of the Feature/Unit suite's time.
  Coverage collection dropped from CI entirely — sharding `test` means no
  single shard's report matches `reference/testing/coverage.md`'s numbers, and
  merging two partial reports is real infrastructure for a number ADR-0009
  already established nothing gates on. Regenerate locally
  (`docs/reference/testing/coverage.md` has the command) when the numbers are
  needed.
- Local database container runs with relaxed durability
  (`innodb_flush_log_at_trx_commit=2`, `sync_binlog=0`, `--skip-log-bin`).
  Production is unaffected — it runs on Forge with MySQL's defaults. A single
  `CREATE TABLE` + `DROP TABLE` inside the container went from 8.28s to 3.16s;
  `migrate:fresh` from 6m51s to seconds.
- `docker/php/Dockerfile` installs Oracle's `mysql-client` rather than Debian's
  `default-mysql-client`, which is MariaDB's and rejects the flags Laravel
  passes to `mysqldump`. `schema:dump` now works, which lets `migrate:fresh`
  load a schema dump instead of replaying every migration.
- `Table::configureUsing()` in `AppServiceProvider` sets `defaultCurrency`
  to EUR. Filament's `money()` columns fall back to `usd` when given no
  argument, so this is set once rather than passed to every money column,
  where a new table would silently render dollars.
- `AssociateAction` and `DissociateAction` removed from all 3 product
  relation managers. `--generate` scaffolds them, but `product_id` is NOT
  NULL on all three child tables, so no row is ever unattached and
  "associate" could only mean reassigning another product's image, spec, or
  variation to this one. Nothing in §6–7 asks for that. They also bypass
  policies entirely — Filament checks only `isReadOnly()` for them — so
  gating rather than removing would have needed a second mechanism.

### Fixed

- Three asset-pipeline failures, all silent, all found only by looking at
  the rendered page. Each has a full entry in
  `docs/how-to/troubleshooting.md`; the short version:

  Tailwind was scanning the **compiled Blade cache**, not Blade source.
  Tailwind 4 anchors automatic source detection at the git root, `.git` sits
  one level above `online-store/`, and the container mounts only
  `online-store/` — so detection collapsed to the two `@source` lines the
  starter kit shipped, one of which is `storage/framework/views`. A class
  therefore existed in the stylesheet only if some page carrying it had
  already been rendered and the cache had not been cleared since, which made
  `php artisan view:clear` actively break working styles. The failure is
  partial rather than total and that is what made it expensive: `grid-cols-2`
  compiled while `lg:grid-cols-3` beside it in the same attribute did not, so
  it read as bad markup for far longer than it should have. Fixed by scanning
  `../views/**/*.blade.php` and `../../app/Livewire/**/*.php`; 16 media
  queries compile now where there had been none.

  Vite wrote `http://0.0.0.0:5173` into `public/hot` — a bind-all address,
  meaningful to a listening socket and meaningless to a browser, so every
  asset request failed at the network layer with nothing in the PHP log.
  `curl` from the host succeeded throughout, which is what made it look like
  the assets were fine. Fixed with `server.origin` and `hmr.host`.

  `Vite::fonts()` was never called. `@vite([...])` does not inject the font
  manifest, so `public/fonts-manifest.dev.json` was generated correctly and
  then referenced by nothing, and Instrument Sans silently fell back to the
  system font. Fixed in the layout head.

- `MergeGuestCart` had no collision handling at all — unlike `AddToCart`,
  which it otherwise mirrors, a concurrent merge or an unrelated `AddToCart`
  landing on the same line surfaced as an uncaught `QueryException`. Now
  catches and retries as an update, same shape as `AddToCart`. The fix
  needed one subtlety `AddToCart`'s doesn't: the retry runs as a savepoint
  inside the merge's own outer transaction, and a savepoint rollback does
  not refresh the transaction's `REPEATABLE READ` snapshot the way a fresh
  top-level transaction does, so the retry's read must `lockForUpdate()`
  rather than read plainly.
- `CalculateCartTotals` threw an uncaught `TypeError` on any cart line whose
  variation or product had been soft-deleted after the line was added — a
  realistic, previously-untested case. Now skips the line rather than
  crashing the cart total.
- `ReportsDomainFailures` (turns an Action's domain exception into a
  Filament notification) caught `RuntimeException` only. Seven of the eight
  domain exceptions extend it; `InvalidCartQuantityException` deliberately
  extends `InvalidArgumentException` instead, per its own docblock, written
  before this trait existed. Would have reached a Filament page as an
  uncaught exception rather than a notification the moment Cart got a
  caller that used the trait — silent only because no such caller exists
  yet. Now catches both.
- `pest --coverage` failed outright everywhere — CI set `coverage: none`
  explicitly and no driver was installed locally.

- `app/Models/User.php` was invalid PHP — an unclosed `$hidden` array and an
  unclosed `profile()` method from a merge conflict resolved by hand. It also
  referenced `Profile`, `Role`, and `Permission`, all removed in the
  `spatie/laravel-permission` switch, and redefined `roles()`, colliding with
  the `HasRoles` trait. Now hand-written and excluded from generation.
- Four factories contained empty class names (`use App\Models\;`,
  `::factory()`) and would not parse.
- 21 factories referenced columns that no longer existed after the schema
  revision. Blueprint does not overwrite existing files, so a second
  `blueprint:build` had layered new columns onto stale ones.
- `AddressFactory` and `OrderAddressFactory` generated `fake()->country()`
  into a `char(2)` column, failing with a truncation error on insert.
- `ProductCategoryFactory` set `'parent_id' => ProductCategory::factory()`
  on a self-referencing key, recursing without termination.
- `UserFactory` hashed a random password per row and left no known password
  for tests to log in with. Now hashes once per process, with an
  `unverified()` state.
- `make:filament-resource --generate` does not infer unique-index validation
  from the schema. All six generated forms had a `slug` field with no
  `->unique()` rule despite a database-level unique constraint on every one
  of them; `AttributeValueForm` needed a composite rule
  (`modifyRuleUsing`) to match `attribute_values`' `UNIQUE(attribute_id,
  slug)` rather than a plain column-level check. Corrected by hand in all
  6 resources.
- `AttributeValueForm.php` imported `Filament\Forms\Get`, which does not
  exist in Filament v4 — `Get`/`Set` moved to
  `Filament\Schemas\Components\Utilities\Get`. Pint and the IDE (which
  cannot resolve any vendor class from the host — see `troubleshooting.md`)
  both missed it; Larastan caught it as `class.notFound`. Would otherwise
  have failed at runtime the first time the closure using it ran.
- `FilamentManager::getUserName()` threw a `TypeError` on every panel page
  after login. It falls back to reading a `name` attribute when the
  authenticated model does not implement `HasName`, and this schema has no
  `name` column — only `first_name`/`last_name`. Fixed by implementing
  `HasName::getFilamentName()` on `User`.
- `DatabaseSeeder` passed `'name' => 'Test User'` to a `users` table with no
  `name` column — silently discarded by Eloquent rather than erroring (see
  the seeded-column entry in `troubleshooting.md`). Replaced with a seeder
  that sets `first_name`/`last_name`, matching the actual schema.
- Every reactive field in `CouponForm` compared `$get('field')` against an
  enum's `->value`. Filament casts an enum-backed `Select`'s state to a
  `BackedEnum`, so each comparison was an object against a string and never
  matched: the percentage cap never applied and 105 reached the database as
  a `CHECK` violation, and `max_discount_amount` and both scope pickers were
  permanently invisible. `Get::enum()` reads either representation. See
  `troubleshooting.md` — Pint and Larastan pass on both versions.
- `decimal:2` on every money field in `ProductForm` and the variations
  relation manager. With one parameter Laravel's rule means *exactly* that
  many decimal places, so a round `20` was rejected. Now `decimal:0,2`.
- `ProductForm` accepted a `discount_price` above `regular_price`, which the
  `CHECK` constraint then rejected as a 500. Now `->lt('regular_price')`.
  The same rule is deliberately absent on variations: a null variation price
  inherits the product's, and the constraint permits a discount alongside it,
  so a naive comparison would reject rows the database accepts. Resolving the
  effective price belongs in an Action.
- `ContactMessage` and `NewsletterSubscriber` still offered a create button
  after their create pages and routes were removed. `CreateAction` lives on
  the `ListRecords` page, not in `getPages()`, and with no route to link to
  Filament rendered it as a modal — which then failed on insert. Both
  policies already refused `create()`, but `Gate::before` grants an
  administrator every ability before any policy runs, so removing the action
  is the only thing that actually holds.
- Product forms and the three relation managers had no `maxLength` on any
  string field. The database rejects the overflow with the truncation error
  described at the top of `troubleshooting.md`; nothing client-side stopped
  it.
- `public/css/filament` and `public/fonts/filament` existed as empty
  directories — the compiled assets were never published, so every asset
  request 404'd and the panel rendered unstyled. `php artisan
  filament:assets` now runs as part of setup; see `README.md`.
- `App\Models\Order` had no `@property` docblock naming its 3 enum-cast
  columns, so Larastan inferred `status`/`payment_status`/`payment_method` as
  raw DB-enum string unions instead of `OrderStatus`/`PaymentStatus`/
  `PaymentMethod` the moment `TransitionOrderStatus` read one back and called
  an enum method on it — `troubleshooting.md`'s "Larastan reports an enum
  comparison as always false" entry had already named `Order` as "the next
  likely case" once this Action existed. Added the three annotations,
  matching `Coupon`'s existing precedent.
- `docs/explanation/tech-stack-overview.md` — said "Nothing exists yet for
  `Product` or `Order`" and "no Actions" under Filament resources, both
  several slices stale (12 resources and 24 Actions exist). Corrected in
  the same pass as this slice, since it is the page the next session reads
  to decide what is safe to build on.

### Removed

- `App\Enums\Role`, `App\Models\UserRoleAssignment`, and the `user_roles`
  migration. The enum + pivot approach was built first, then replaced by
  `spatie/laravel-permission` — §3.5 requires runtime-editable permissions.

### Open

- PHP version: `composer.json` declares `^8.3`, the lockfile requires 8.4.
  Not pinned.
- Content translation storage shape and default locale.
- Audit log shape.
- Enum value lists exist in two places: the 19 `enum()` literals in the
  migrations, and `App\Enums`. The migrations are frozen by the append-only
  rule, so the duplication cannot be removed retroactively. Migrations added
  from here on should use `OrderStatus::values()` rather than a literal array,
  which keeps the copy generated instead of typed.
- §37 standard 19 (unique generated filenames on upload). Filament's default
  naming path was not traced to an actual stored filename against this
  version — no seeded or fixture data exercises a real upload, only
  synthetic paths. Verify by uploading through the panel and reading back
  `product_images.path`, then close the standard or add a
  `getUploadedFileNameForStorageUsing()` callback if the default collides.
- Whether Filament 4.12.6's `Repeater` with `->reorderableWithDragAndDrop()`
  actually preserves array submission order the way `SetVariationImages`
  assumes, and whether the `ViewField` thumbnail partial re-renders live
  against its sibling `Select` inside a modal nested in a relation
  manager's row action — built and statically verified, never opened in a
  browser. Same open item for `->rules([(new Dimensions())...])`: Larastan
  now confirms it type-checks, but nobody has uploaded an undersized image
  through the actual form to confirm Filament surfaces the rejection.
- The §11 discount window is implemented twice: `ResolveVariationPrice::
  windowActive()`, which owns it, and `ProductList::discountIsActive()`,
  which duplicates it. A card can therefore advertise a sale price the cart
  refuses to honour. The catalogue cannot simply call the existing resolver
  because that one resolves a *variation* and a card renders a *product*;
  the fix is a `ResolveProductPrice` in `app/Support/` with `windowActive()`
  moved into it and `ResolveVariationPrice` calling through. Owed on the
  product detail page, which needs the same answer at both levels.
- `Catalogue\ProductList` has no tests. Storefront reads are not Actions and
  so fall outside the Action suite by design (ADR-0014); they need
  `Livewire::test(...)` feature tests, a shape this project has not written
  yet. The sort allow-list is the first thing that warrants one, being the
  guard on attacker-controlled input.
- Catalogue search is `LIKE '%term%'` — unindexable, and it matches
  mid-word. Named as a placeholder in ADR-0014 rather than a design;
  full-text or Scout is a decision to make when search quality is the work,
  not underneath the first catalogue page.
- Per-variation product images are a fixture gap, not a code gap. The detail
  page swaps the gallery when a variation is selected and leads with that
  variation's own photographs — verified on `PWR-0001`, where picking Red
  changes the main image to `pwr0001-side.jpg`. But only one product in the
  demo catalogue has variations whose *leading* image differs: 142 of 169
  products carry a single image, and the remaining multi-variation products
  point every colourway at the same file. Selecting a colour therefore looks
  like it does nothing on almost every product, while doing exactly the right
  thing. Closing this means authoring per-colour images into
  `database/fixtures/demo/*.json` and fetching them with `demo:fetch-images`,
  which is fixture work rather than component work.
- `App\Support\ResolveVariationImage` has a full test suite and no production
  caller. It answers "which single image represents this variation", which is
  what a cart line, an order line, a wishlist row, or a listing thumbnail
  needs — the detail page is the one screen that wants the whole ordered set,
  so it deliberately does not use it. Not dead code, but ahead of its
  callers; the cart page is where it should land.
