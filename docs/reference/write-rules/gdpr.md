# GDPR erasure — expected behaviour

What the three `App\Actions\Gdpr\` Actions do to every table that holds a
customer's personal data, alone and when a second actor touches the same
account. `EraseCustomer` is the bulk of this page; `ExportCustomerData`
(read-only) and `PurgeAnonymisedOrders` (the retention purge) have short
sections at the end. Facts as of 2026-09-09, measured against the running
stack. The *policy* — what is kept vs. deleted and why — is
[ADR-0019](../../adr/0019-regulatory-compliance.md); `explanation/gdpr.md` is the
narrative. This page is the outcomes.

## What enforces any of this

`EraseCustomer` and nothing else. A direct `$user->forceDelete()` still
fires the FK behaviours (null / cascade) but leaves every anonymisable
snapshot — order identity, review author, contact-message body — intact,
which is an incomplete erasure that looks complete.

Callers: `App\Livewire\Account\DeleteAccount` (`/account/delete`,
self-service, no actor) and `ViewUser`'s `erase` header action (Filament,
with an actor, authorized `erase` on the `User`).

## Per table

| Table | Row survives? | What changes |
|---|---|---|
| `users` | no | `forceDelete()` — the soft-delete scope is deactivation, not erasure |
| `orders` | **yes** | `email` → `erased-{id}@anonymized.invalid`, `first_name`/`last_name` → `[erased]`, `phone` → `''`, `customer_note` → `null`, `anonymized_at` → now. `user_id` nulled by the FK. Every amount, `serial_number`, `status`, `payment_*`, `currency`, `internal_note`, `invoice_*` untouched — that is the accounting record |
| `order_addresses` | yes | `first_name`/`last_name` → `[erased]`, `phone` → `''`, `city`/`postcode` → `[erased]`, `street`/`courier_office_code`/`courier_office_name` → `null`. `country` kept (2-letter code, place of supply) |
| `product_reviews` | yes | `author_name` → `Anonymous`; `user_id` nulled by the FK. `body`, `rating`, `product_id` untouched |
| `returns` | yes | `reason` → `[erased]`, `resolution_note` → `null` (customer free text — same class as `orders.customer_note`). `status`, `refunded_amount`, `requested_at`, `resolved_at` untouched — the refund record (ADR-0020) |
| `return_items` | yes | nothing — no personal data, and it belongs to the retained order |
| `coupon_redemptions` | yes | nothing but `user_id` (nulled by the FK). `email_hash` stays — peppered pseudonymisation, own retention basis |
| `newsletter_subscribers` | no | deleted where `user_id` matches **or** `email` matches (a pre-registration guest row has `user_id = null`) |
| `contact_messages` | no | deleted, same id-or-email match |
| `addresses` | no | `cascadeOnDelete` when the user row goes |
| `carts`, `cart_items` | no | `cascadeOnDelete` |
| `wishlist_items` | no | `cascadeOnDelete` |
| `order_status_histories` | yes | `user_id` nulled by the FK; the `previous_status`/`new_status`/`reason` audit trail stays (§19) |

## Idempotency

Only orders with `anonymized_at IS NULL` are rewritten. Running the Action
twice — a retried request, or an admin erasing an account a scheduled purge
already partly touched — rewrites nothing the second time. After the user
row is gone, the Action has no `User` to be called with, so re-entry is not
reachable through either caller.

## One actor at a time

| Scenario | Outcome |
|---|---|
| Two erasure requests for the same customer race | The user row and its orders are `lockForUpdate()` inside one transaction. The second waits for the first to commit, then finds no user (self-service `firstOrFail` on the locked row) or an already-anonymised order set (Filament path: no-op) |
| Erasure races a `TransitionOrderStatus` on one of the customer's orders | Both lock the `orders` row. Whichever commits first wins; the status transition against an anonymised order still succeeds (status is not identity), and erasure against an order mid-transition waits for that lock |
| Erasure races the customer placing a new order | `CreateOrder` needs the `User` row (`user_id`) it is passed; once erasure has committed the `forceDelete`, the checkout actor lookup fails with `CheckoutActorRemovedException` rather than creating an orphan |

## Authorization

- **Self-service** (`DeleteAccount`) — `auth` middleware + current password
  (`current_password` rule) + a typed `DELETE`. No permission: erasing your
  own data is a right, not a grant. `EraseCustomer` is called with **no
  actor**, so `UserPolicy::erase` never runs.
- **Filament** (`ViewUser`) — `erase_user` permission (administrator-only
  via `Gate::before`) **and** `$model->id !== $user->id`. An admin erasing
  their own account here would lose panel access mid-transaction; they use
  self-service.

Proven red by removing the mechanism: `EraseCustomerTest` reverts the
`Gate::authorize` call and confirms an unauthorized Filament-path erasure
then succeeds; `DeleteAccountTest` reverts the password and confirmation
rules and confirms the account is erased without either.

## `ExportCustomerData` (Art. 15 / 20)

Read-only, no transaction. Walks the same tables `EraseCustomer` does —
including newsletter and contact rows matched by *email* as well as
`user_id` — and returns a structured array the `/account/data` route
streams as JSON. `coupon_redemptions` appears as the *fact* of a redemption
(which coupon, when) but never the `email_hash`: the hash is derived data
the customer cannot verify. An anonymised order is included, flagged
`anonymised: true`, with its `[erased]` values — the export tells the truth
about what is held, not what was once held. Each order also carries its
`returns` (status, reason, refunded amount, timestamps) and their items
(product name + quantity) — ADR-0020.

## `PurgeAnonymisedOrders` (Art. 5(1)(e))

| | |
|---|---|
| Scope | `orders` where `anonymized_at` is set **and** older than `config('gdpr.order_retention_years')` |
| Effect | `$order->delete()` — every child of `orders` is `cascadeOnDelete` (`order_items`, `order_addresses`, `order_status_histories`, `payments`, `shipments`, `coupon_redemptions`, `returns` → `return_items`), so the row and its whole subtree go |
| Disabled | when the config value is `null`: returns `null`, the command prints "disabled", nothing is deleted |
| Refuses | a config value that is set but not a positive integer (`RuntimeException`) — it will not guess |
| Locking | the matched orders are `lockForUpdate()` inside one transaction, so a purge racing a late refund or status change on an about-to-be-deleted order serialises on the `orders` row |
| Idempotency | trivially — a second run finds the rows already gone |

## Known gaps

- **`activity_log` is not scanned.** `spatie/laravel-activitylog` records
  nothing customer-facing yet. When it does, its `causer` and `properties`
  rows join this routine.
- **Concurrency coverage is single-process.** The "one actor at a time"
  table above is asserted by `EraseCustomerTest` in one process, which
  proves the guard but not the lock. A `tests/Concurrency/` pass with the
  `race:worker` subprocess pattern — two erasures, erasure vs. transition,
  purge vs. refund — is a follow-up.
- **`OrderFactory` sets `anonymized_at` by default**, so a factory-built
  order looks pre-erased to this Action. Tests that want a live order pass
  `anonymized_at => null` explicitly. Not a production concern —
  `CreateOrder` never sets the column — but a trap when writing tests here.
