# GDPR: deletion, retention, and erasure

Status: implemented (ADR-0019, 2026-09-09). The schema this doc designed is
built. `App\Actions\Gdpr\EraseCustomer` is the Art. 17 routine, at
`/account/delete` and `ViewUser`; `ExportCustomerData` is the Art. 15 / 20
export at `/account/data`; `PurgeAnonymisedOrders` (`orders:purge-anonymised`,
weekly) is the retention purge, whose *period* is the one thing still left
to a human — `config('gdpr.order_retention_years')`, set from BG accounting
law before go-live. Per-table behaviour is `reference/write-rules/gdpr.md`.
Regulatory scope beyond GDPR is `reference/regulatory-compliance.md`.

## Two different deletions

**Soft delete** is an application concern. A deactivated account, a
discontinued product. The row stays, foreign keys stay valid, order history
stays readable. `softdeletes` on `User`, `Product`, `ProductVariation`.

**Hard delete** is a legal one — an erasure request under GDPR Art. 17. The
personal data has to actually go.

Those are different operations with different consequences, which is why
both exist rather than one standing in for the other.

## The conflict

Erasure and record-keeping pull in opposite directions. An order is an
invoice, and invoices are retained for tax purposes for years. A customer
asking for erasure cannot delete the shop's accounting records.

GDPR resolves this itself: Art. 17(3)(b) exempts processing required for a
legal obligation. The order survives. What has to go is the personal data
attached to it that isn't required by that obligation.

So erasure is not `DELETE FROM orders`. It is: delete the user row,
anonymize what the order snapshotted, and keep the financial record intact.

## What that requires from the schema

`Order` snapshots the customer's identity — `email`, `phone`, `first_name`,
`last_name` — rather than joining to `users`. That snapshot exists for guest
checkout, which has no user row at all, but it is also what makes erasure
possible: the order can outlive the user because it never depended on it.

- `Order.user_id` is nullable, so removing the user does not remove the
  order.
- `Order.anonymized_at` records that the identity columns on the order and
  its `OrderAddress` rows have been overwritten. Without a marker there is
  no way to tell an anonymized order from one placed by a customer named
  "Deleted User".
- `ProductReview.user_id` is nullable, with `author_name` as a snapshot. A
  review survives its author as an anonymous review. Deleting it instead
  would silently change the product's rating, which is a data integrity
  problem dressed up as a privacy feature.

## Coupon limits without storing an email

`usage_limit_per_customer` needs to recognize a returning customer. Guests
have no `user_id`, so the only identifier available at checkout is the email
address.

Storing it creates the conflict: erase the email and the limit stops working
for that person, who can then redeem a one-per-customer coupon again.
Keeping it means retaining personal data after an erasure request.

`CouponRedemption` stores `email_hash char(64)` instead — SHA-256 of the
normalized (lowercased, trimmed) email, with an application pepper held in
`.env`, never in the database.

This works because enforcing the limit never requires reading the email. It
requires answering "has this address redeemed this coupon before", which is
an equality check, and equality survives hashing. Hash the address at
checkout, compare against stored hashes, count.

What it buys:

- A database dump contains no email addresses in this table.
- The pepper lives outside the database, so a dump alone cannot be brute
  forced against a list of known addresses. Email has low enough entropy
  that an unpeppered hash would be trivially reversible by dictionary
  attack — the pepper is what makes this meaningful rather than
  decorative.
- Erasure does not have to touch the row. The limit keeps working.

What it does not buy: **a hash is still personal data under GDPR** when it
can single out an individual, which this can. It is pseudonymization, not
anonymization. It reduces exposure and it makes the retention defensible
under legitimate interest in fraud prevention — it does not put the data
outside the regulation. Treat `coupon_redemptions` as a table holding
personal data, just a less exposed form of it.

The same reasoning applies anywhere an identifier is needed only for
comparison and never for contact.

## Foreign key behaviour

Blueprint cannot express delete behaviour, so this needs editing by hand
after generation. Without it, hard-deleting a user throws a constraint
violation rather than doing anything.

`nullOnDelete()` — the record outlives the user:
`orders.user_id`, `product_reviews.user_id`,
`order_status_histories.user_id`, `inventory_movements.created_by_id`,
`coupon_redemptions.user_id`

`cascadeOnDelete()` — the record has no meaning without the user:
`addresses.user_id`, `carts.user_id`, `wishlist_items.user_id`

## Tables holding personal data

What `EraseCustomer` walks: `users`, `addresses`, `orders`,
`order_addresses`, `product_reviews`, `coupon_redemptions`,
`newsletter_subscribers`, `contact_messages`, `carts`, `cart_items`,
`wishlist_items`, `order_status_histories`.
`reference/write-rules/gdpr.md` says what happens to each.

`activity_log` (spatie/laravel-activitylog) also records a causer and
arbitrary `properties` JSON, which can capture personal data depending on
what gets logged. Worth checking before it starts recording customer-facing
actions.

## Open

- **The retention period itself.** `orders:purge-anonymised` and its Action
  are built; `config('gdpr.order_retention_years')` (default 11, `.env`
  `GDPR_ORDER_RETENTION_YEARS`) drives them. What is not decided is the
  number — Bulgarian accounting and tax law set the minimum, and it has to
  be confirmed with counsel before go-live. Until then the conservative
  default stands; set it to `null` to disable the purge outright.
- `activity_log` (spatie/laravel-activitylog) records nothing
  customer-facing yet. When it does, its `causer` and `properties` rows
  join the erasure routine.
- Concurrency coverage for the erasure and purge paths is single-process
  (`EraseCustomerTest`). A `tests/Concurrency/` pass proving the locks, not
  just the guards, is a follow-up — `reference/write-rules/gdpr.md`.

**Resolved by ADR-0019:** contact messages and newsletter subscriptions are
**deleted outright** on erasure — no accounting obligation attaches, and an
anonymized free-text message is not reliably anonymized.
