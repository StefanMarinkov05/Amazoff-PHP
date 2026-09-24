# Architecture at a glance

A condensed orientation for a reviewer who wants the mental model without
reading all of `docs/`. Every section below points back to the real source —
this page summarizes, it doesn't replace.

## What this is

A Laravel 13 / Filament 4 e-commerce app: storefront (Livewire) + admin panel
(Filament) sharing one codebase, one auth guard, one set of business rules.
Products with variations (colour/size), a blog-style "Journal", Stripe and
cash-on-delivery payments, Econt/Speedy courier integration, GDPR-compliant
account erasure, and a 14-day right-of-withdrawal returns flow.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 |
| Admin panel | Filament 4 |
| Storefront interactivity | Livewire (via Filament) |
| Roles/permissions | `spatie/laravel-permission` — roles are DB rows, not an enum, so they're editable at runtime |
| Courier APIs | Saloon connectors (Econt, Speedy) behind `App\Contracts\CourierGateway` |
| Payments | `stripe/stripe-php`, Payment Intents + Elements (not Checkout Sessions) |
| HTML sanitization | `stevebauman/purify` — article and review bodies are user input |
| Static analysis | Larastan (PHPStan, Laravel-aware) |
| Tests | Pest 5 / PHPUnit 13, plus a real-browser suite (`tests/Browser`) |
| Local dev | Docker Compose — `app` (PHP-FPM), `webserver` (nginx), `db` (MySQL 8), `vite`, `mailpit` |

Full table: `docs/reference/tech-stack.md`.

## The non-negotiable rules

From `docs/reference/coding-conventions.md` — the single source; everything
below is a summary.

- **Actions own business logic.** One class per command,
  `app/Actions/{Area}/{Verb}{Noun}.php`, a single `handle()`. Controllers and
  Livewire components are thin on the write path: validate → call Action →
  respond. Reads go straight to Eloquent — a read has no invariant for an
  Action to own.
- **Filament resources call the same Actions as the storefront**, wherever a
  rule actually exists (a write spanning more than one table, or an invariant
  the schema can't express on its own). Plain lookup tables (`Brand`, `Tag`,
  `Attribute`, `Carrier`, product categories) keep Filament's default CRUD —
  wrapping a single-table save in an Action buys nothing.
- **No repository pattern.** Eloquent is the repository.
- **Backed enums** for every fixed value set, with behaviour on them
  (`canTransitionTo()` where illegal moves exist). Filament reads `HasLabel`/
  `HasColor` straight off the enum. The one exception is roles, which are
  `spatie/laravel-permission` rows because permissions must be editable at
  runtime.
- **Money is `decimal(10,2)`, never float.** Arithmetic goes through
  `App\Support\Money`, never raw `bc*` calls.
- **Eager-loading discipline.** Any Filament table column crossing a relation
  gets that relation eager-loaded explicitly — Filament does no eager-loading
  of its own, and a missed one is an N+1 that still renders, so it goes
  unnoticed without review.
- **Contested state is `DB::transaction` + `lockForUpdate()`** on the row the
  invariant actually lives on (stock, coupon usage caps) — the transaction
  alone doesn't prevent the race. `docs/reference/write-rules/concurrency.md`
  has the full contested-resource map and lock order.
- **`TransitionOrderStatus` is the only writer of `orders.status`** and of
  `order_status_histories`. Never assign `->status` directly. It picks the
  inventory side effect (`ReleaseStock`/`CompleteSale`/`RestockReturn`) by
  target status, keyed so a caller can't bypass the effect by calling the
  Action directly instead of a wrapper.
- **Couriers are abstracted, Stripe is not.** Saloon connector + interface for
  Econt/Speedy (two real implementations); no interface over Stripe (one
  implementation, nothing to swap it for) — an interface over a single
  implementation is speculative generality, not testability (ADR-0001).
- **Validation via Form Requests.** Never `$request->all()`.
- **Authorization on every mutating path and every route taking an ID.** A
  hidden button is not security.

## Security rules that are this project's, not the framework's

- Scope queries to the user — `auth()->user()->orders()->findOrFail($id)`,
  never `Order::findOrFail($id)`.
- `canAccessPanel()` on `User` checks role membership; Filament is not
  protected past login by default.
- **Stripe webhook: CSRF-excluded *and* signature-verified.** The route sits
  outside the `web` middleware group entirely (no session, no cookies, no
  CSRF token to exempt), and `VerifyStripeWebhookSignature` is the sole gate
  that replaces it. One without the other is a free-products vulnerability.
- Article and review bodies are purified before rendering — `{!! !!}` escapes
  nothing.
- Idempotency via a UNIQUE constraint plus a caught violation, never
  check-then-act (`CartAlreadyCheckedOutException`, `coupon_redemptions`'
  per-order uniqueness).
- Public order tracking requires order number **and** email — sequential
  numbers alone would enumerate every customer's address.
- Totals are always recalculated server-side; the browser total is never
  trusted. `CreateOrder`'s signature carries no total input at all, so
  there's no field to bypass through.

Full detail: `docs/explanation/security-model.md`.

## Permissions model

107 permissions, 3 roles, 4 demo accounts, named `{ability}_{resource}`
(`viewAny_product`, `updateStatus_order`, `publish_article`). Defined once in
`App\Support\PermissionCatalogue`.

| Role | Scope |
|---|---|
| `administrator` | Everything, via `Gate::before` — 0 explicit permission rows, so the catalogue can grow without drift |
| `content_editor` | Articles, article categories, tags — 16 permissions |
| `warehouse_employee` | Orders (routine status advances only), shipments, inventory, read-only carriers — 12 permissions |

Notable design choices, not oversights:

- `cancel_order`/`refund_order` are separate abilities from `updateStatus_order`
  — reserved for administrators (ADR-0011). The **same** "Change status" menu
  on an order generates a different set of options per role: a warehouse
  employee sees routine advances, an administrator sees those plus Cancel and
  Refund — one generated menu, gated per target status.
- `create_order`, `delete_order`, `create_payment` don't exist —
  `OrderPolicy::create()`/`::delete()` return `false` outright. An order
  exists because a customer checked out; a payment row is written only by the
  Stripe webhook. Nobody creates either through the panel, so the permission
  could only ever be ticked by mistake.
- **A policy cannot deny an administrator anything** — `Gate::before` short-
  circuits before any policy method runs. A rule of the form "nobody may do
  X, not even the administrator" has to live in the Action or the page, never
  in a policy method.

Full detail: `docs/reference/permissions.md`.

## Order and payment lifecycle

Every order is created at `OrderStatus::New` regardless of payment method —
`CreateOrder` never advances it; the caller takes the first hop
(`New → AwaitingPayment` for card, `New → Confirmed` for COD confirmation).
11 order statuses total; the full transition graph with inventory side
effects is `docs/explanation/system-overview.md`'s composite diagram.

A card order that never completes payment is swept by `ExpireUnpaidOrders`
(every minute, `orders:expire-unpaid`), cancelling it and releasing stock —
ADR-0022.

Returns are a **separate** state machine (`ReturnStatus`, ADR-0020) that runs
*beside* `orders.status`, never through `TransitionOrderStatus` — a granular
return that also transitioned the order would double-restock against
`OrderStatus::Returned`'s whole-order effect. A fully-returned order still
reads `Delivered` in the customer's order list; the return's own status is
the truth for "was this returned."

## GDPR / compliance

Account deletion (`/account/delete`, self-service, or the panel's `erase_user`
action) runs `EraseCustomer`, which **anonymizes rather than hard-deletes**
where the record must legally survive: `orders` keeps every amount, status,
and serial number (the accounting record) but overwrites `email`,
`first_name`/`last_name`, `phone`; `order_status_histories` keeps the whole
audit trail with `user_id` nulled. Tables with no retention basis
(`newsletter_subscribers`, `contact_messages`, `addresses`, carts, wishlist)
are actually deleted. Full per-table behaviour:
`docs/reference/write-rules/gdpr.md`.

The regulatory posture (what's kept, what's deleted, and why) is
ADR-0019; `docs/explanation/gdpr.md` is the narrative version.

## ADRs (25, one decision per file)

The decision in each is frozen once accepted — a changed mind gets a new ADR
marked "Superseded," never a rewrite of the old one.

| # | Decision |
|---|---|
| 0001 | Tech stack selection |
| 0002 | Product catalogue schema |
| 0003 | Seeding the database |
| 0004 | Where state transitions live |
| 0005 | Validation in the database, not only in PHP |
| 0006 | Where authorization lives |
| 0007 | How Actions are written |
| 0008 | Choosing a concurrency mechanism per contested resource |
| 0009 | Code coverage — collected, not gated |
| 0010 | CI split into parallel, hand-sharded jobs |
| 0011 | Side effects of an order status transition |
| 0012 | Laravel Boost — adopted, generic guidance audited against this project's ADRs |
| 0013 | How a variation owns images |
| 0014 | How a storefront page reads |
| 0015 | How author-written HTML is sanitized |
| 0016 | Payment Intents with Elements, not Checkout Sessions |
| 0017 | Pest browser tests, not standalone Playwright |
| 0018 | Upgrade to Pest 5 / PHPUnit 13 |
| 0019 | Regulatory compliance posture |
| 0020 | The `OrderReturn` aggregate |
| 0021 | Omnibus prior-price display |
| 0022 | The unpaid-order lifecycle |
| 0023 | Railway as the beta deploy target |
| 0024 | Railway builds with Railpack, not a custom Dockerfile |
| 0025 | Split seed and upload media disks, routed by path prefix |

Full text: `docs/adr/`.

## Where to go deeper

- **`docs/explanation/system-overview.md`** — the whole system as one state
  machine (visitor journey, payment states, order states), one diagram.
- **`docs/reference/coding-conventions.md`** — the rules above, unabridged.
- **`docs/reference/actions.md`** — every Action, what it writes, who can
  call it, what it throws.
- **`docs/reference/write-rules/`** — per-aggregate expected behaviour:
  refusals, races, what a change does to state that already exists.
- **`docs/reference/permissions.md`** — the full permission/role table.
- **`docs/explanation/security-model.md`** and **`gdpr.md`** — the security
  and compliance layers in full.
- **`docs/adr/`** — all 25 decisions, unabridged.
