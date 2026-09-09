# ADR-0019: GDPR erasure — anonymise the order, delete everything else

Status: Accepted
Date: 2026-09-09 · Deciders: Stefan Marinkov

## Context

`docs/explanation/gdpr.md` designed the schema for a GDPR Art. 17 erasure
request — `orders.anonymized_at`, nullable `user_id` FKs, `author_name`
snapshots on reviews, the peppered `coupon_redemptions.email_hash` — but
left the *routine* itself and two policy questions open:

- Are contact messages and newsletter subscriptions deleted outright on
  erasure, or anonymised like orders?
- What is the retention period for an anonymised order, and what purges it?

Nothing in the codebase erases anything. `User` has `SoftDeletes`; a soft
delete is deactivation, not erasure — the personal data stays in the row.

An erasure request has to be answerable both by the customer (self-service)
and by staff (an email to the shop asking for it).

## Decision

**One Action, `App\Actions\Gdpr\EraseCustomer`, run inside a transaction
with the user row and its orders locked. It anonymises what a legal
obligation forces the shop to keep, and hard-deletes everything else.**

### Kept, with the identity overwritten

**Orders and their `order_addresses`.** An order is an invoice; Bulgarian
accounting law requires invoices to be retained, and Art. 17(3)(b) exempts
processing required by a legal obligation. The financial record stays; the
personal data that is not part of it goes:

| Column | After erasure |
|---|---|
| `orders.email` | `erased-{id}@anonymized.invalid` (RFC 2606 reserved TLD, still `email`-shaped for the `email` column and its index, unique per order) |
| `orders.first_name`, `orders.last_name` | `[erased]` |
| `orders.phone` | `''` |
| `orders.customer_note` | `null` (free text the customer wrote — not part of the financial record) |
| `orders.anonymized_at` | `now()` — the marker `gdpr.md` describes |
| `order_addresses.first_name`, `last_name` | `[erased]` |
| `order_addresses.phone` | `''` |
| `order_addresses.city`, `postcode` | `[erased]` |
| `order_addresses.street`, `courier_office_code`, `courier_office_name` | `null` |
| `order_addresses.country` | **kept** — a 2-letter code is not on its own identifying, and it is the place-of-supply for the VAT record |

`orders.internal_note` is **kept**: it is staff processing notes about the
order, not the customer's personal data. `invoice_company` /
`invoice_vat_number` / `invoice_eik` are **kept** when `invoice_required` —
they are the B2B invoice.

Only orders with `anonymized_at IS NULL` are touched, so a re-run is a
no-op on already-erased orders (idempotency).

**Product reviews.** `user_id` is nulled by the FK (`nullOnDelete`);
`author_name` is overwritten to `Anonymous`. The review body and rating
stay — deleting the review would silently move the product's average
rating, which is a data-integrity change dressed up as a privacy one
(`gdpr.md`). An erased review is an anonymous review.

### Hard-deleted

- **`newsletter_subscribers`** and **`contact_messages`** — matched by
  `user_id` *or* the user's email (a guest who subscribed before
  registering has a `null` `user_id`). No accounting obligation attaches to
  either, so deletion is the honest answer — the first of `gdpr.md`'s two
  open questions, resolved as "delete".
- **`addresses`, `carts`, `cart_items`, `wishlist_items`** — deleted by the
  existing `cascadeOnDelete` FKs when the user row goes. They have no
  meaning without the user.
- **The `users` row** — `forceDelete()`, not `delete()`. The soft-delete
  scope is for deactivation.

### Left untouched

**`coupon_redemptions`.** `email_hash` is SHA-256 with an application
pepper held outside the database (`gdpr.md`, "Coupon limits without storing
an email"). It is pseudonymised, its retention is defensible under
legitimate interest in fraud prevention, and the per-customer coupon limit
would silently break for a re-registering person if it were cleared. The
`user_id` is nulled by its FK; the hash stays.

### Authorisation

- **Self-service** — `App\Livewire\Account\DeleteAccount` at
  `/account/delete`, behind `auth`, requires the current password
  (`Hash::check`), then calls the Action for `auth()->user()` and logs the
  session out. A customer erasing themselves needs no permission.
- **Staff** — a `UserResource` action calls the same Action.
  `UserPolicy::erase()` gates it on the new `erase_user` permission
  (administrator-only via `Gate::before`) **and** `$model->id !==
  $user->id` — an admin erasing their own account through the admin tool
  would lose panel access mid-transaction; they use self-service like
  anyone else.

## Consequences

- New Action area `App\Actions\Gdpr\`. New permission `erase_user`
  (`PermissionCatalogue::DOMAIN_ABILITIES['user']`). New route
  `/account/delete`, new Livewire component + view.
- No migration — every column and FK this needs already exists (`gdpr.md`
  built them).
- `gdpr.md` moves from "Status: design" to describing live behaviour, and
  its "Open" section loses the delete-vs-anonymise question.
- **Retention purge is still not built.** The anonymised order should be
  deleted outright once the Bulgarian accounting retention minimum expires;
  nothing does that yet. This stays an open item in `gdpr.md` — it is a
  scheduled command against `anonymized_at`, not a change to this Action,
  and it needs the exact statutory period confirmed first.
- `spatie/laravel-activitylog` is installed but records nothing
  customer-facing yet (`tech-stack-overview.md`). If it starts to, its
  `causer` and `properties` rows join the erasure routine — noted in
  `gdpr.md`, not handled here because there is nothing to handle.
- An erased customer's past order is invisible to them: `/account/orders`
  scopes to `auth()->user()->orders()`, and there is no user. Public order
  tracking (`/orders/track`) needs the email, which is now
  `erased-{id}@anonymized.invalid` — deliberately unguessable. The order is
  a closed accounting record, not a live customer touchpoint.

## Alternatives rejected

**Delete the order too.** Violates the accounting-retention obligation; a
customer cannot compel the shop to destroy its books.

**Anonymise contact messages / newsletter rows instead of deleting.** No
legal basis requires keeping them, and an anonymised free-text contact
message ("please call me on 0888…") is not reliably anonymised. Deletion is
both simpler and more complete.

**Clear `coupon_redemptions.email_hash`.** Breaks the per-customer coupon
cap for a returning person and removes a fraud-prevention control, for no
privacy gain the pepper-plus-hash design does not already give.

**A queued job.** Erasure is small (single-customer scope) and must be
seen to complete before the self-service flow logs the user out and tells
them it is done. Synchronous, in a request, inside one transaction.
