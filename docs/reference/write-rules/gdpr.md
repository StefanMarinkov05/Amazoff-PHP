# GDPR erasure — expected behaviour

What `App\Actions\Gdpr\EraseCustomer` does to every table that holds a
customer's personal data, alone and when a second actor touches the same
account. Facts as of 2026-09-09, measured against the running stack. The
*policy* — what is kept vs. deleted and why — is
[ADR-0019](../../adr/0019-gdpr-erasure.md); `explanation/gdpr.md` is the
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

## Known gaps

- **No retention purge.** An anonymised order should be deleted outright
  once the Bulgarian accounting retention minimum expires. Nothing does
  this. It is a scheduled command against `anonymized_at`, not a change to
  this Action, and needs the statutory period confirmed first —
  `explanation/gdpr.md`, "Open".
- **`activity_log` is not scanned.** `spatie/laravel-activitylog` records
  nothing customer-facing yet. When it does, its `causer` and `properties`
  rows join this routine.
- **`OrderFactory` sets `anonymized_at` by default**, so a factory-built
  order looks pre-erased to this Action. Tests that want a live order pass
  `anonymized_at => null` explicitly. Not a production concern —
  `CreateOrder` never sets the column — but a trap when writing tests here.
