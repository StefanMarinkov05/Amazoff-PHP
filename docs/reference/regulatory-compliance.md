# Regulatory compliance — what applies and where it lives

An internship project, not legal advice, and not a substitute for a lawyer
before this shop takes real money. What this page is: a map from each EU /
Bulgarian regime that plausibly applies to an online shop selling its **own**
goods from Bulgaria, to the code, ADR, or gap that answers it. "Gap" here
means the obligation is understood and not yet built, not that it was
missed.

The shop is **first-party**: it sells its own inventory, runs a blog
(`journal`), and shows customer product reviews. It is **not** a
marketplace, a hosting service, or an intermediary — which is the single
fact that decides how much of the DSA applies (almost none).

---

## GDPR — Regulation (EU) 2016/679

The regime with the most code behind it. `explanation/gdpr.md` is the
narrative; `reference/write-rules/gdpr.md` is the erasure outcomes;
[ADR-0019](../adr/0019-regulatory-compliance.md) is the erasure decision;
[ADR-0015](../adr/0015-html-sanitization.md) and
`explanation/security-model.md` cover the processing-security side.

| Article | Obligation | Where |
|---|---|---|
| Art. 5(1)(c) — data minimisation | Snapshot only what checkout needs; the coupon cap stores a peppered hash, never the email | `explanation/gdpr.md` "Coupon limits without storing an email"; `CouponRedemption.email_hash` |
| Art. 5(1)(e) — storage limitation | Soft-delete for deactivation; erasure for Art. 17; retention purge | `App\Actions\Gdpr\PurgeAnonymisedOrders` + `orders:purge-anonymised` (weekly), driven by `config('gdpr.order_retention_years')` — a config a human sets from BG accounting law |
| Art. 6(1)(b) — lawful basis, contract | Order processing, order-confirmation email | checkout flow; `App\Mail\OrderPlaced` (queued from `CheckoutPage::placeOrder`) |
| Art. 6(1)(a) / ePrivacy — consent | Newsletter opt-in, cookie banner | newsletter double opt-in **gap** (sequenced after the email); cookie policy page exists (`/cookies`), a consent *mechanism* is a **gap** |
| Art. 7(3) — withdraw consent as easily as given | One-click unsubscribe link in every marketing email | **gap** — part of the newsletter slice |
| Art. 12–14 — transparency / privacy notice | A privacy policy the customer can read | `/privacy` page exists; its text is placeholder and needs a real notice — **gap** |
| Art. 15 / 20 — access / portability | Give the customer a machine-readable copy of their data | **done** — `App\Actions\Gdpr\ExportCustomerData` + `/account/data`, streamed as JSON |
| Art. 16 — rectification | Edit name / email / phone / addresses | `EditProfile`, `ManageAddresses` |
| Art. 17 — erasure | Anonymise the order, delete the rest | `App\Actions\Gdpr\EraseCustomer`, ADR-0019, `write-rules/gdpr.md` |
| Art. 25 / 32 — security by design, processing security | Scoped queries, `canAccessPanel()`, purified user input, CSRF+signature on the Stripe webhook, idempotency via UNIQUE | `reference/coding-conventions.md` security rules; `explanation/security-model.md` |
| Art. 30 — records of processing | A register of what is processed, why, retention | **gap** — this page is a start; a formal ROPA is a document task, not code |
| Art. 33–34 — breach notification | A process, not code | **gap** — out of scope for the build, in scope for go-live |
| Art. 44+ — international transfers | Stripe (US) processes payment data | Stripe is the sub-processor; a DPA with Stripe and a transfer-mechanism note is a **go-live gap**, `explanation/stripe-payments.md` is where it would be recorded |

---

## ePrivacy — Directive 2002/58/EC (BG: Electronic Communications Act) + Omnibus

| Rule | Obligation | Where |
|---|---|---|
| ePrivacy Art. 5(3) — cookies / storage access | Consent before non-essential cookies; the session cookie and CSRF token are essential and exempt | `/cookies` page describes them; **no consent gate** because nothing non-essential is set yet (no analytics, no ad pixels). If any are added, a consent mechanism becomes mandatory first |
| ePrivacy Art. 13 — unsolicited marketing | Opt-in for the newsletter (double opt-in), unsubscribe in every send | **gap** — the newsletter slice. Today `SubscribeToNewsletter` subscribes immediately with no confirmation and there is no unsubscribe route |
| Omnibus Dir. (EU) 2019/2161 — price reductions | When a product shows a reduced price, also show the lowest price in the 30 days before the reduction | **gap** — `products.discount_price` / `discount_starts_at` exist; the "was N, lowest recent N" display does not. Needs a price-history table or a computed lowest-recent value |
| Omnibus — review authenticity | State whether and how the shop ensures reviews come from real purchasers | **done** — enforced by `ProductDetails::canReview()` / `CreateProductReview` (delivered order line only), and stated in a sentence under the reviews heading |
| Omnibus — "personalised pricing" disclosure | Disclose if a price is personalised by automated decision-making | N/A — every customer sees the same price |

---

## Consumer Rights — Directive 2011/83/EU (BG: Consumer Protection Act, ЗЗП)

Directly relevant to a B2C shop. Several of these overlap with features
already flagged in `misc/todo.md`.

| Rule | Obligation | Where |
|---|---|---|
| Art. 6 — pre-contractual information | Main characteristics, total price incl. taxes, delivery cost, trader identity, payment/delivery terms | product pages show price incl. VAT and `min_order_quantity`; delivery cost is computed at checkout; static pages `/delivery`, `/payment-information`, `/terms` carry the terms — **their text is placeholder** |
| Art. 8(2) — "order with obligation to pay" | The order button must be labelled unambiguously | **done** — the checkout submit button reads "Order with obligation to pay" |
| Art. 8(7) — confirmation on a durable medium | Confirm the concluded contract, with all Art. 6 information, on a durable medium within a reasonable time | **done** — `App\Mail\OrderPlaced`: line items with variation/image/price/qty, totals with VAT, delivery + billing address, payment method label, order number + tracking link, withdrawal information. `explanation/transactional-email.md` |
| Art. 9–15 — 14-day right of withdrawal | The customer may withdraw within 14 days of delivery, with a model withdrawal form and clear information on the right | **gap** — `misc/todo.md` "Customer-facing returns" tracks this. Needs a returns request flow, the 14-day window as an Action-level guard, and the withdrawal information in the pre-contract text |
| Art. 6(1)(h) + Annex I(B) — model withdrawal form | Provide the standard form | **done** — `/returns/withdrawal-form`, linked from the order email; the online returns request is the returns slice |

---

## Digital Services Act — Regulation (EU) 2022/2065

**Mostly does not apply.** The DSA regulates *intermediary services* —
mere-conduit, caching, and hosting — and layers extra duties on *online
platforms* (services that store and disseminate information from
recipients, at their request, to the public) and *marketplaces*
(platforms that let third-party traders conclude contracts with
consumers). This shop is none of those: it sells its own goods and hosts
its own content.

| DSA concept | Applies here? |
|---|---|
| Notice-and-action, statement of reasons, internal complaint system, trusted flaggers, out-of-court dispute settlement (Arts. 16–21) | **No** — these are hosting-service / online-platform duties. Product reviews are not "information provided by recipients disseminated to the public" in the platform sense; the shop moderates its own review wall (§24, `ApproveProductReview`) as an editorial choice, not a DSA obligation |
| Marketplace trader traceability / "know your business customer" (Arts. 30–32) | **No** — no third-party traders |
| Ad transparency, recommender-system transparency (Arts. 26–27) | **No** — no advertising surface, no algorithmic feed. The `journal` and `Home` sections are editorially curated (`is_featured`), which the DSA does not reach |
| Point of contact / legal representative (Arts. 11–13) | **No** for a non-intermediary; a general "contact us" (`/contact`) is good practice regardless |

If a marketplace ever gets built — third parties listing their own products
— the DSA applies in full and needs its own ADR before any of that code is
written.

---

## Accessibility — Directive (EU) 2019/882 (European Accessibility Act)

In force from **28 June 2025**; e-commerce services must meet the accessibility
requirements (in practice, WCAG 2.1 AA). Micro-enterprises (<10 staff,
<€2m turnover) providing services are exempt — likely this shop's status,
but the exemption is not something to rely on by default.

| Area | Where |
|---|---|
| Responsive layout, three widths | `tests/Browser/ResponsiveTest` (ADR-0017); real devices / landscape / 200% zoom still **gap** (`browser-testing.md`) |
| Contrast, focus order, screen-reader semantics, keyboard nav | **gap** — no audit run; `chrome-devtools-mcp`'s `a11y-debugging` skill and `pest-plugin-browser`'s `assertNoAccessibilityIssues()` are the tools |
| Tap-target sizes | **done** for the footer links and breadcrumbs (24px minimum via `-my-1 py-1`); the skip-link is large once focused |

---

## Tax / invoicing — Bulgarian VAT Act (ЗДДС) + accounting law

| Rule | Where |
|---|---|
| Prices shown to consumers include VAT | product prices are stored gross; `vat_rate` is snapshotted per order line at checkout — `explanation/money.md` |
| Invoice retention | the reason `EraseCustomer` anonymises the order rather than deleting it — ADR-0019, `explanation/gdpr.md` "The conflict" |
| Invoice content (B2B) | `orders.invoice_company` / `invoice_vat_number` / `invoice_eik`, kept through erasure when `invoice_required` |
| Retention *period* and the purge that enforces it | **gap** — `explanation/gdpr.md` "Open" |

---

## Summary of gaps, by how load-bearing

**Legally load-bearing, sequenced now:**

1. Order-confirmation email — CRD Art. 8(7) durable medium (also the point
   of the feature).
2. Newsletter double opt-in + unsubscribe — ePrivacy Art. 13.

**Legally load-bearing, tracked, not yet sequenced:**

3. 14-day right of withdrawal + returns flow + model form — CRD Arts. 9–15
   (`misc/todo.md`).
4. "Order with obligation to pay" button wording — CRD Art. 8(2). One
   string.
5. Real privacy policy and terms text — GDPR Arts. 12–14, CRD Art. 6.
   Currently placeholder.
6. Data-export (Art. 15) Action — same shape as `EraseCustomer`.

**Compliance-hygiene, not blocking a build:**

7. Omnibus 30-day-low price display; the review-authenticity sentence.
8. Accessibility audit (EAA — exemption likely but unconfirmed).
9. ROPA (Art. 30), breach process (Arts. 33–34), Stripe DPA / transfer
   note — go-live document tasks.
10. Retention purge for anonymised orders.
