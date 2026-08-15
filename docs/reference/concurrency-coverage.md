# Concurrency — what is contested, what protects it, what proves it

Facts as of 2026-08-15. Why the mechanisms differ is
`explanation/concurrency-and-locking.md`; the locking decision for
cross-table invariants is ADR-0008. What a given pair of concurrent writes
actually produces is `reference/product-write-rules.md` — this page is the
mechanisms, that one is the outcomes.

A row here counts as *verified* only if the test has been observed failing
with its mechanism deleted and passing with it restored. Anything else is
listed as unverified, because a test that has never failed is not evidence.

## Contested resources

| Resource | Kind | Mechanism | Where |
|---|---|---|---|
| `inventories.reserved_quantity` | row contention inside one request | `lockForUpdate` on `inventories` + `CHECK` + `increment()` | `ReserveStock`, `ReleaseStock` |
| `products.is_available` vs its variations | cross-table invariant, no constraint possible | `lockForUpdate` on `products` (aggregate root) | `UpdateProduct`, `RemoveProductVariation` |
| `product_variations` removal vs held stock | cross-table invariant | `lockForUpdate` on `products`, then `inventories` | `RemoveProductVariation` |
| `products` descriptive columns | lost update across two requests | **none** — see below | `UpdateProduct` |
| a catalogue row soft-deleted after a model was loaded | stale in-memory model | re-read inside the transaction | `ReserveStock`, `AddProductVariation` |
| `inventories` outliving an erased variation | FK `NO ACTION` | child deleted before parent, plus four refusals | `ForceDeleteProductVariation` |
| `products.sku`, `products.slug`, `product_variations.sku` | duplicate insert | `UNIQUE` constraint (ADR-0005) | schema |
| one `is_main` image per product | blind write, no read to invalidate | a single `UPDATE`, no lock needed | `SetMainProductImage` |

### Lock order

`products` before `inventories`, always. `ReserveStock` and `ReleaseStock`
take `inventories` alone and never reach for a product, so no cycle exists.

`CreateOrder` will lock several `inventories` rows at once and must sort them
by primary key first; nothing does that yet because nothing yet locks more
than one.

## What is tested

### Verified by deletion

| Scenario | Asserts | File |
|---|---|---|
| Two processes reserving the last unit | loser gets `InsufficientStockException`, not `QueryException` | `tests/Concurrency/ReserveStockConcurrencyTest.php` |
| Publish racing removal of the last variation | exactly one winner; loser gets `ProductRequiresVariationException`; the invariant holds either way | `tests/Concurrency/PublishProductConcurrencyTest.php` |
| Reserved above current rejected at the database | `QueryException` from `chk_inventories_reserved_not_above_current` | `tests/Concurrency/ReserveStockConcurrencyTest.php` |
| Product rolls back when a variation fails | no product, no variation, no stock row survives | `tests/Feature/Actions/Catalogue/CreateProductTest.php` |
| Variation rolls back when its stock row cannot be written | neither row survives | `tests/Feature/Actions/Catalogue/AddProductVariationTest.php` |
| Authorization on all four catalogue Actions | denied actor throws, writes nothing | `tests/Feature/Actions/Catalogue/` |
| Actor passed down from `CreateProduct` | `create_product` alone is not enough | `tests/Feature/Actions/Catalogue/CreateProductTest.php` |
| Last variation of an available product | removal refused | `tests/Feature/Actions/Catalogue/RemoveProductVariationTest.php` |
| Variation with reserved stock | removal refused | `tests/Feature/Actions/Catalogue/RemoveProductVariationTest.php` |
| Reserving against a soft-deleted variation | refused; nothing held, no ledger row | `tests/Feature/Actions/Inventory/ReserveStockTest.php` |
| Adding a variation to a soft-deleted product | refused; nothing written | `tests/Feature/Actions/Catalogue/AddProductVariationTest.php` |
| Erasing a variation before its stock row | stock row deleted first, so no error 1451 | `tests/Feature/Actions/Catalogue/ForceDeleteProductVariationTest.php` |
| Erasing a variation with a ledger, a cart line, or nothing left to sell | refused | same |

The two concurrency files differ in what their assertion can be, and the
difference is worth knowing before writing a third.

`ReserveStockConcurrencyTest` **cannot** assert the winner count.
`chk_inventories_reserved_not_above_current` produces exactly one winner
whether or not the lock is present; only the *kind* of failure changes.

`PublishProductConcurrencyTest` **must** assert the winner count. MySQL cannot
express "an available product has at least one live variation" across two
tables and ADR-0004 rejected triggers, so there is no backstop: without the
lock both writes commit and both processes report success.

### Pinned by construction, not by a deleted mechanism

`tests/Concurrency/MainProductImageConcurrencyTest.php` asserts that two
concurrent promotions both succeed and leave exactly one main image. It cannot
be made red by deleting a mechanism, and that is the finding rather than a
gap: `SetMainProductImage` reads nothing to decide anything, so there is no
check-then-act window, and one `UPDATE` cannot interleave with itself.

An earlier two-statement version took a `products` lock. Removing that lock
left the test green — correctly, because the two-statement form is also safe
against a lost invariant; what it risks is two promotions acquiring the same
rows in opposite order and deadlocking, which is error 1213 and a 500. One
statement rules that out, so the lock went rather than the test.

### Known broken, asserted as such

| Scenario | Current behaviour | File |
|---|---|---|
| Two employees saving product forms opened at the same time | the second silently reverts the first's untouched fields | `tests/Feature/Actions/Catalogue/ConcurrentProductEditTest.php` |
| Same, through the admin panel | same — Livewire re-resolves the record on hydration, so the panel is always the losing case | same |
| Both employees changing the same field | last write wins, no warning | same |
| Partial-field submission with a cross-field `CHECK` | `QueryException` where a full payload would have silently reverted the other edit | same |

These assert measured behaviour, including behaviour that is wrong. They flip
red the day optimistic concurrency is added, which is the intended signal to
update them.

### Not covered

- Coupon redemption against §21's caps. `RedeemCoupon` does not exist.
- Two staff transitioning one order. `TransitionOrderStatus` does not exist.
- Guest cart merge summing into `UNIQUE(cart_id, product_variation_id)`.
- Duplicate Stripe events against `UNIQUE(stripe_event_id)`.
- `orders.serial_number` allocation.
- Deadlock between two orders locking the same variations in opposite order.
- Two processes creating a product with the same SKU. The `UNIQUE` constraint
  makes the outcome certain, so there is nothing a race test would add.

The first five are slices 4–7 in the working plan.

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
