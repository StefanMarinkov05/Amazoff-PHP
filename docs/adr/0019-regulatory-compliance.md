# ADR-0019: Regulatory compliance posture

Status: Accepted
Date: 2026-09-09 · Deciders: Stefan Marinkov

An internship project, not legal advice. This ADR records the decisions
about *which* regimes bind a first-party Bulgarian online shop and *how*
this codebase answers each — the frozen "what we decided and why".
[`docs/reference/regulatory-compliance.md`](../reference/regulatory-compliance.md)
is the living map from each obligation to the code or gap; this ADR is the
reasoning behind the shape of that map, and it does not get rewritten as
gaps close — a changed decision gets a superseding ADR.

## Context

The shop sells its **own** inventory from Bulgaria to EU consumers, runs an
editorial blog (`journal`), and shows moderated customer product reviews.
It is not a marketplace, a hosting service, or an intermediary. That single
fact decides how much of several regimes applies.

Before this ADR the only regulatory work in the codebase was GDPR-shaped:
scoped queries, the peppered coupon hash, `orders.anonymized_at` in the
schema. There was no erasure routine, no consent mechanism, no
durable-medium order confirmation, no returns flow, and the privacy/terms
pages were placeholder.

## Decisions

### 1. GDPR (Reg. (EU) 2016/679) — the core

- **Art. 17 erasure: anonymise the order, delete everything else.**
  `App\Actions\Gdpr\EraseCustomer`, one transaction, user row and orders
  `lockForUpdate()`.
  - Kept with identity overwritten: `orders` + `order_addresses` (`email` →
    `erased-{id}@anonymized.invalid`, names → `[erased]`, `phone` → `''`,
    `city`/`postcode` → `[erased]`, `street`/office → `null`,
    `customer_note` → `null`, `anonymized_at` set). Every amount,
    `serial_number`, `status`, `payment_*`, `currency`, `internal_note`,
    and — when `invoice_required` — `invoice_*` are **kept**: that is the
    financial record, and Art. 17(3)(b) exempts processing required by a
    legal obligation (Bulgarian accounting law requires invoice retention).
    `order_addresses.country` is kept — a 2-letter code is not on its own
    identifying and it is the place-of-supply for the VAT record.
  - `product_reviews`: `user_id` nulled by the FK, `author_name` →
    `Anonymous`, body and rating kept. Deleting the review would silently
    move the product's average rating — a data-integrity change dressed up
    as a privacy one.
  - Hard-deleted: `newsletter_subscribers` and `contact_messages` (matched
    by `user_id` *or* email — a pre-registration guest row has a `null`
    `user_id`); `addresses`, `carts`, `cart_items`, `wishlist_items` by the
    existing cascade FKs; the `users` row (`forceDelete`, not the
    soft-delete deactivation scope).
  - Left untouched: `coupon_redemptions`. Its `email_hash` is SHA-256 with
    an application pepper held outside the database — pseudonymised, with a
    fraud-prevention retention basis, and clearing it would silently reopen
    a one-per-customer coupon to a re-registering person.
  - Idempotent on `anonymized_at IS NULL`. Synchronous, not queued — the
    self-service flow logs the user out and tells them it is done, so it
    must be done.
  - Reachable at `/account/delete` (self-service, current password + typed
    `DELETE`, no permission — erasing your own data is a right) and from a
    `ViewUser` panel action for an emailed request (`erase_user` permission,
    administrator-only via `Gate::before`, blocked for your own account).

- **Art. 15 access / data portability: a self-service export.**
  `App\Actions\Gdpr\ExportCustomerData` walks the same tables `EraseCustomer`
  does and returns a structured JSON document of everything held about the
  customer. Downloadable from `/account/data`, synchronous (single-customer
  scope). Machine-readable JSON satisfies "structured, commonly used,
  machine-readable" (Art. 20).

- **Art. 5(1)(e) storage limitation: a retention purge, period as config.**
  `orders:purge-anonymised` (scheduled) deletes anonymised orders older
  than `config('gdpr.order_retention_years')`. The exact figure is
  Bulgarian accounting law's, not something this project can invent, so it
  is an `.env` value (`GDPR_ORDER_RETENTION_YEARS`), defaulting to a
  conservative **11 years** and to *disabled* (`null`) if unset in a way
  that is explicit rather than silent. `docs/explanation/gdpr.md` records
  that the number must be confirmed with counsel before go-live.

- **Arts. 12–14 transparency: a real privacy notice**, replacing the
  placeholder `/privacy` page. Covers data categories, legal bases (per
  processing purpose), retention, the data subject's rights and how to
  exercise them (linking `/account/data` and `/account/delete`), processors
  (Stripe, Econt/Speedy, the mail host), and the supervisory authority.
  Every section carries a visible **"DRAFT — review with counsel before
  go-live"** banner: it reads like a real notice and is explicitly not
  certified.
  > **Done** — `/privacy` and `/terms` are full structured drafts with the
  > banner (a `draft` prop on `<x-site.prose-page>`) and `[placeholder]`
  > company details; `LegalPagesTest` pins the shape. Counsel review and the
  > real registration details are the remaining go-live step.

- **Art. 30 records of processing, Arts. 33–34 breach notification,
  Art. 44+ transfers (Stripe DPA):** document tasks, not code. Structured
  in `docs/explanation/gdpr.md` and `regulatory-compliance.md` as go-live
  items with the required content listed; not built.

### 2. ePrivacy (Dir. 2002/58/EC; BG Electronic Communications Act)

- **Art. 13 unsolicited marketing: newsletter double opt-in.** Subscribe →
  `NewsletterStatus::Pending` → a confirmation email with a signed link →
  clicking it sets `Subscribed`. Every marketing send carries a signed
  one-click unsubscribe link (`/newsletter/unsubscribe/{token}`), so
  withdrawing consent is as easy as giving it (GDPR Art. 7(3)). A `Pending`
  row that is never confirmed is not marketed to and is purged after 30
  days.

- **Art. 5(3) cookies: a consent mechanism, gated on need.** A consent
  banner + a `cookie_consent` cookie are added now so the mechanism exists,
  but the shop currently sets only essential cookies (session, CSRF), which
  are exempt — the banner records the choice and gates a `hasConsent()`
  helper that any future analytics/marketing script must check before
  loading. `/cookies` is updated to describe this.

### 3. Consumer Rights (Dir. 2011/83/EU; BG Consumer Protection Act, ЗЗП)

- **Art. 8(7) durable medium: an order-confirmation email.** Sent on every
  successfully placed order (COD immediately; card after the payment is
  confirmed). Contains all Art. 6 pre-contractual information as concluded:
  every line item with its variation, image, unit and line price and
  quantity; the order totals with VAT broken out; the delivery address or
  courier office; the billing address when different; the payment method
  label; the order number and a tracking link; and the 14-day withdrawal
  information with a link to the model form. **No card or payment-token
  data**, ever. This is also GDPR Art. 6(1)(b) contract processing.

- **Art. 8(2) "order with obligation to pay":** the checkout submit button
  reads **"Order with obligation to pay"** (BG: "Поръчка със задължение за
  плащане"), not "Place order".

- **Arts. 9–15 right of withdrawal: a returns flow.** Its own decision
  record in this ADR's sibling section below; summary: a customer may
  request a return within 14 days of delivery, the request moves
  `requested → approved / denied → refunded`, and the refund reuses
  `RefundPayment` and `PaymentResource`'s machinery rather than a second
  path. The withdrawal information and the model withdrawal form
  (Annex I(B)) are a static page linked from checkout, the confirmation
  email, and the order-details page.

### 4. Omnibus (Dir. (EU) 2019/2161)

- **Prior-price display:** when a product shows a reduced price, it also
  shows the lowest price in the 30 days before the reduction. A
  `product_price_history` row is written whenever a product's
  `regular_price` or `discount_price` changes (through `UpdateProduct`),
  and the storefront computes "lowest in the last 30 days" from it.
  > **Implemented in [ADR-0021](0021-omnibus-prior-price-display.md)**, which
  > adds a daily `products:snapshot-prices` sweep alongside the
  > write-triggered capture — a scheduled discount window opening or closing
  > changes the effective price with no `UpdateProduct` call, so write-only
  > history would miss it.

- **Review authenticity:** `CreateProductReview` already only accepts a
  review from a customer with a *delivered* order line for that product. A
  sentence stating this is added near the reviews block, which is what
  Omnibus requires disclosed. No change to the enforcement.

### 5. Digital Services Act (Reg. (EU) 2022/2065) — scoping decision

**The DSA's hosting-service, online-platform, and marketplace duties do not
apply.** Notice-and-action, statements of reasons, internal complaint
handling, trusted flaggers, out-of-court dispute settlement (Arts. 16–21),
trader traceability (Arts. 30–32), and ad/recommender transparency
(Arts. 26–27) are all duties of intermediaries that store and disseminate
*recipients'* information to the public, or that let third-party traders
contract with consumers. This shop does neither. Product-review moderation
(§24, `ApproveProductReview`) is an editorial choice, not a DSA obligation.
A general point of contact (`/contact`) is kept as good practice.

**If a marketplace is ever built** — third parties listing their own goods
— the DSA applies in full and needs its own ADR before that code is
written. This decision is scoped to the first-party shop as it stands.

### 6. Accessibility (Dir. (EU) 2019/882, EAA)

In force from 28 June 2025; e-commerce services must meet WCAG 2.1 AA in
practice. A micro-enterprise service exemption likely applies but is not
relied on. `tests/Browser/ResponsiveTest` covers the responsive layer; a
contrast / focus-order / screen-reader / keyboard audit is a tracked gap
(`regulatory-compliance.md`), with `chrome-devtools-mcp`'s `a11y-debugging`
and `pest-plugin-browser`'s `assertNoAccessibilityIssues()` as the tools.
The 15 sub-24px nav/footer tap targets (`misc/todo.md`) are fixed in this
pass.

### 7. Tax / invoicing (BG VAT Act ЗДДС, accounting law)

Prices shown to consumers include VAT (stored gross, `vat_rate`
snapshotted per line). Invoice retention is *why* erasure anonymises rather
than deletes the order (decision 1). The retention period and its purge are
decision 1's config value.

## The returns flow (decision 3, Arts. 9–15)

> **Implemented as the `OrderReturn` aggregate — see
> [ADR-0020](0020-order-return-aggregate.md)**, which records the decisions
> this section left open (the model name, independence from `orders.status`,
> cash-on-delivery, where the 14-day date comes from). The design below is
> unchanged; ADR-0020 is the detail, not a reversal.

**A `Return` aggregate with its own status enum and a `RequestReturn` /
`ReviewReturn` / `RefundReturn` Action trio.**

- `returns` table: `order_id`, `status`
  (`requested`/`approved`/`denied`/`refunded`), `reason`, `resolution_note`,
  `requested_at`, `resolved_at`, timestamps.
- `return_items`: which order lines, and quantities.
- **The 14-day window is an Action guard**, not UI-only: `RequestReturn`
  refuses if the order is not `Delivered` or if
  `delivered_at + 14 days < now()`. "Delivered when" comes from the
  `order_status_histories` row for the `Delivered` transition — no new
  column.
- **Refund reuses `RefundPayment`**: `RefundReturn` composes it, so there
  is one path that moves money and one place the refund cap lives.
- **Restock**: an approved return that is refunded calls `RestockReturn`
  (the inventory Action that already exists) for its lines.
- Customer surface: "Request a return" on `/account/orders/{order}` for an
  eligible order, a form choosing lines + reason; status shown on the same
  page. Staff surface: a `ReturnResource` in the panel, approve/deny with a
  note, then refund.
- The model withdrawal form is a static `/returns/withdrawal-form` page
  (also downloadable), linked from checkout, the confirmation email, and
  the order page — required by Art. 6(1)(h) + Annex I(B) whether or not the
  customer uses the online flow.

## Consequences

- New Action areas `App\Actions\Gdpr\` (erasure, export, purge) and
  `App\Actions\Returns\`. New models `Return`, `ReturnItem`,
  `ProductPriceHistory`, `NewsletterConfirmation` (or a `token` +
  `confirmed_at` on `newsletter_subscribers`). New enums `ReturnStatus`,
  `NewsletterStatus::Pending`. Migrations for all of the above — the schema
  freeze does not apply to genuinely new aggregates, only to editing merged
  migrations.
- First transactional email in the codebase: a base Markdown mail layout,
  queued Mailables (`OrderPlaced`, `NewsletterConfirmation`,
  `NewsletterUnsubscribed`). `docs/explanation/transactional-email.md` is
  new and records the pattern (queued, the layout, `MAIL_MAILER` per
  environment, mailpit locally).
- New permissions: `viewAny_return`, `view_return`, `update_return` (staff
  review), and a `refund` domain ability on `return`. Flow through
  `PermissionCatalogue` like every other.
- New config file `config/gdpr.php` (`order_retention_years`) and
  `config/cookies.php` or a `consent` block.
- New scheduled commands: `orders:purge-anonymised`,
  `newsletter:purge-unconfirmed`. Registered in `routes/console.php` /
  `bootstrap/app.php`'s schedule.
- New storefront routes: `/account/data`, `/newsletter/confirm/{token}`,
  `/newsletter/unsubscribe/{token}`, `/returns/withdrawal-form`,
  `/account/orders/{order}/return`. New static/near-static pages for the
  privacy notice and the withdrawal form.
- `regulatory-compliance.md` is updated as each gap closes; this ADR is
  not.
- **What stays a documented gap** after this pass: the ROPA (Art. 30), the
  breach-notification process (Arts. 33–34), the Stripe DPA and transfer
  mechanism note (Art. 44+), a real accessibility audit, and counsel review
  of the privacy notice and T&Cs. All are go-live tasks that need a human,
  not code.

## Alternatives rejected

**Delete the order on erasure.** Violates the accounting-retention
obligation; a customer cannot compel the shop to destroy its books.

**A hard-coded retention period.** The figure is statutory and
jurisdiction-specific; hard-coding a guess is worse than a config a human
sets from the actual law.

**Treat product reviews as DSA-regulated content.** They are not
recipient-provided information disseminated to the public in the
intermediary sense; the shop curates its own review wall editorially.
Applying notice-and-action machinery to a first-party review section is
compliance theatre.

**Single opt-in for the newsletter.** ePrivacy Art. 13 and the Bulgarian
implementation require prior consent for electronic marketing; a
pre-ticked box or an immediate subscribe is not consent. Double opt-in is
the defensible form and the one that produces an audit trail.

**A queued erasure.** The self-service flow must show completion before it
logs the user out. Synchronous, single transaction.
