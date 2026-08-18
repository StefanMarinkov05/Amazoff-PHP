# Concurrency — what is contested, what protects it, what proves it

Facts as of 2026-08-17. Why the mechanisms differ is
`explanation/concurrency-and-locking.md`; the locking decision for
cross-table invariants is ADR-0008. What a given pair of concurrent writes
actually produces — the specific exception, which side wins, what the row
looks like after — is `reference/write-rules/product.md`,
`reference/write-rules/cart.md`, `reference/write-rules/coupon.md`, and
`reference/write-rules/order.md`, one
per aggregate. **This page names what is contested and which test proves it;
it does not restate the outcome.** A scenario belongs here once, as a
pointer, and in exactly one outcomes page in full — repeating the outcome
text in both is how the two drift.

A row here counts as *verified* only if the test has been observed failing
with its mechanism deleted and passing with it restored. Anything else is
listed as unverified, because a test that has never failed is not evidence.

## Contested resources

| Resource | Kind | Mechanism | Where |
|---|---|---|---|
| `inventories.reserved_quantity` | row contention inside one request | `lockForUpdate` on `inventories` + `CHECK` + `increment()` | `ReserveStock`, `ReleaseStock` |
| `products.is_available` vs its variations | cross-table invariant, no constraint possible | `lockForUpdate` on `products` (aggregate root) | `UpdateProduct`, `RemoveProductVariation` |
| `product_variations` removal vs held stock | cross-table invariant | `lockForUpdate` on `products`, then `inventories` | `RemoveProductVariation` |
| `products` descriptive columns | lost update across two requests | **none** — `reference/write-rules/product.md`, "Two actors at once" and "Known gaps" | `UpdateProduct` |
| a catalogue row soft-deleted after a model was loaded | stale in-memory model | re-read inside the transaction | `ReserveStock`, `AddProductVariation` |
| `inventories` outliving an erased variation | FK `NO ACTION` | child deleted before parent, plus four refusals | `ForceDeleteProductVariation` |
| `products.sku`, `products.slug`, `product_variations.sku` | duplicate insert | `UNIQUE` constraint (ADR-0005) | schema |
| one `is_main` image per product | blind write, no read to invalidate | a single `UPDATE`, no lock needed | `SetMainProductImage` |
| `cart_items` via `UNIQUE(cart_id, product_variation_id)` | insert-vs-insert across two requests | catch `UniqueConstraintViolationException`, retry as `increment()` — no lock, since a row that does not exist yet cannot be locked | `AddToCart`, `MergeGuestCart` |
| `coupons.total_usage_limit` / `usage_limit_per_customer` vs `coupon_redemptions` | cross-table invariant, no constraint possible | `lockForUpdate` on `coupons` before either `COUNT` | `RedeemCoupon` |
| `coupon_redemptions` via `UNIQUE(coupon_id, order_id)` | insert-vs-insert, same order retried or double-submitted | catch `UniqueConstraintViolationException`, return the existing row | `RedeemCoupon` |
| two orders reserving the same variation(s) | row contention across several `inventories` rows in one transaction | `ReserveStock`'s own lock, called once per line, sorted by `product_variation_id` first | `CreateOrder` |

### Lock order

`products` before `inventories`, always. `ReserveStock` and `ReleaseStock`
take `inventories` alone and never reach for a product, so no cycle exists.

`products`, then `coupons`, then `inventories`. `RedeemCoupon` locks
exactly one `coupons` row, independent of which products are in the cart,
so it sits between the other two rather than racing either. No Action
today takes a `products` lock and a `coupons`/`inventories` lock in the
same transaction — the `products` half of the declared order is still
unexercised.

`CreateOrder` locks several `inventories` rows at once — one per cart
line, via `ReserveStock` — sorted by `product_variation_id` first, so two
orders sharing lines never acquire in opposite sequence. Unverified by any
test: `cart_items`'s own `UNIQUE(cart_id, product_variation_id)` index
happens to return rows pre-sorted by `product_variation_id` for this query
shape on the current MySQL version, so removing the explicit sort does not
turn any test red either. `reference/write-rules/order.md`, "Known gaps"
has the reasoning for keeping it anyway.

## What is tested

Which test proves which scenario is cited directly in
`reference/write-rules/product.md`, `reference/write-rules/cart.md`,
`reference/write-rules/coupon.md`, and `reference/write-rules/order.md`, next
to the outcome it proves — not duplicated here as a second index.
`MainProductImageConcurrencyTest.php` is pinned by
construction rather than by a deleted mechanism, and
`ConcurrentProductEditTest.php` is known broken, asserted as such; both notes
live with their outcomes too.

Single-actor facts — a rollback when one Action's own multi-step write fails
partway, an authorization denial, an ordering refusal — are not concurrency
scenarios even when a test happens to prove them alongside a race in the same
file. They live in each outcomes page's "One actor at a time" table, not
here.

### Choosing the assertion

The concurrency files split into three assertion shapes, and knowing which
one applies is the step that decides whether a new test is worth anything —
worked through in full in `explanation/concurrency-and-locking.md`, "Choosing
the assertion." In brief, because the shape generalises to any future
resource:

**A `CHECK` constraint backstops the resource.** The race test cannot assert
a winner count — the constraint produces exactly one winner whether or not
the lock is present. Only the *kind* of failure changes: a handled domain
exception with the lock, `QueryException` without it. `ReserveStock`/
`ReleaseStock` are the current example.

**Nothing backstops the resource.** The race test *must* assert the winner
count, because without the lock both writes commit and both processes report
success — there is no database-level floor under them. The
publish-vs-variation-removal pairing is the current example.

**Both attempts are legitimate, and both must win.** Not a mutual-exclusion
race at all. There is no lock and no winner count to assert; the mechanism
under test is a catch-and-retry around a `UNIQUE` constraint, and the
discriminator is whether the loser recovers silently or surfaces the
collision as an uncaught `QueryException`. `AddToCart`/`MergeGuestCart` are
the current example — racing itself. Racing a **different** Action is not
automatically the same test: see "Environment notes" for why, and
`cart.md`'s "Known gaps" for what that cost in practice.

`SetMainProductImage`'s main-image promotion is a fourth, degenerate case:
a blind single-statement write with no read to invalidate, so there is no
mechanism to delete and the test asserts final state as a property rather
than a guard.

### Not covered

- Two staff transitioning one order. `TransitionOrderStatus` does not exist.
- Duplicate Stripe events against `UNIQUE(stripe_event_id)`.
- Two processes creating a product with the same SKU. The `UNIQUE` constraint
  makes the outcome certain, so there is nothing a race test would add.

The first two are slices 6–7 in the working plan.

`orders.serial_number` allocation and deadlock between two orders locking
the same variations in opposite order are no longer open — both are settled
by `CreateOrder`'s design: the serial number is derived from the row's own
auto-increment id rather than a separately-allocated sequence, and lines
are locked sorted by `product_variation_id`. The sort is unverified by a
live test (see the lock-order section above), which is a gap in
*evidence*, not an open design question.

## The enum layer, verified by mutation

`tests/Unit/Enums/` covers the four transition matrices and sweeps all twelve
enums. No database, no application: 91 tests in under a second.

ADR-0004 justified putting legality on the enum partly because "the matrix is
plain data… testable without a database, a request, or a user". Nothing
collected that until 2026-08-15.

Deletion is the wrong verification for pure functions — there is no mechanism
to remove, only a value to get wrong. The equivalent is mutation: apply one
plausible edit and check the suite notices. Thirteen were run.

| Mutant | Result |
|---|---|
| `Delivered => [Returned, New]` — walk an order backwards | killed |
| `Refunded => [Paid]` — leak from a terminal state | killed |
| `Shipped => [… Cancelled]` — cancel after shipped | killed |
| `New => [AwaitingPayment, Cancelled]` — drop the COD path | killed |
| `Failed => [… ]` without `Paid` — drop the late Stripe success | killed |
| `Delivered => [Returned, Delivered]` — self-transition | killed |
| `PartiallyRefunded => [Refunded]` — drop the repeat partial refund | killed |
| `Archived => [Draft]` — tighten the permissive article matrix | killed |
| `! in_array(...)` — invert the lookup | killed |
| `values()` off by one against `cases()` | killed |
| a label arm returning `''` | killed |
| two cases sharing a backing value | killed |
| `in_array(...)` without `strict: true` | **survived** |

The survivor is an **equivalent mutant**, not a gap. PHP 8 compares enum
instances by identity, so loose and strict comparison agree for every input
`canTransitionTo(self $status)` can receive — and the parameter type makes a
non-enum argument a `TypeError` before the comparison is reached. Measured,
not assumed. No test can kill it because no behaviour differs, and a test
written to try would assert nothing.

The flag stays: it is correct, it costs nothing, and it stops being equivalent
the moment the signature widens.

## Environment notes

`tests/Concurrency/` is a separate suite in `phpunit.xml` and is excluded from
`RefreshDatabase` in `tests/Pest.php`, because a transaction that is rolled
back rather than committed leaves rows no second connection can see. Those
tests commit their fixtures and truncate in `afterEach` with
`Schema::disableForeignKeyConstraints()`.

Workers are booted by hand rather than through `php artisan tinker <file>`,
which never exits. Both spin-wait on a shared `microtime(true)` instant so
they enter the critical section together; Laravel's boot time is hundreds of
milliseconds and the window under test is microseconds.

Single-process fault injection cannot substitute for a second process when the
mechanism under test is a lock. A row lock does not constrain the transaction
that holds it, so a test that injects a conflicting write on the same
connection passes with the lock and without it.

**A same-Action race is fair by construction; a cross-Action race is not.**
`explanation/concurrency-and-locking.md`, "A cross-Action race needs a
fourth thing: a rendezvous" has the mechanism and the measurement;
`cart.md`'s "Known gaps" has what it cost in practice.
