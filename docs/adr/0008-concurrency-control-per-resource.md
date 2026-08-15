# ADR-0008: Choosing a concurrency mechanism per contested resource

Status: Proposed
Date: 2026-08-15 · Deciders: Stefan Marinkov

## Context

CLAUDE.md states one rule: contested state gets `DB::transaction` **and**
`lockForUpdate()`. It names stock and coupon usage. ADR-0005 added 45 `CHECK`
constraints as a backstop, and `explanation/concurrency-and-locking.md` works
through why the transaction alone does not close the stock race.

Building the catalogue Actions turned up two contested resources that the rule
as written does not fit, and applying it literally to either produces
something wrong.

**First: an invariant spanning two tables with no constraint to back it.**
§6–7 requires every sellable product to have at least one variation. Three
Actions can break it — `CreateProduct`, `UpdateProduct`,
`RemoveProductVariation` — and each is check-then-act. MySQL cannot express
the rule: it spans `products` and `product_variations`, and ADR-0004 already
rejected triggers for putting business rules where no test reads them. So
unlike stock there is no backstop at all, and two concurrent requests each
guarding their own side both commit.

Locking the rows being written does not help here. `UpdateProduct` writes
`products` and `RemoveProductVariation` writes `product_variations`; they
would contend on different rows and neither would ever wait.

**Second: a lost update across two requests.** Two employees open a product's
edit form, each saves one field, and the second silently reverts the first —
because Livewire re-resolves the record from the database on hydration, so the
model's originals are current at save time while the submitted payload is not,
and a field nobody touched therefore counts as dirty. Measured, not inferred.

The literal reading of the rule says lock it. That is not implementable: a
PHP-FPM request ends when the form renders, so no transaction survives to the
submission. Any lock spanning think time has to be an advisory application
lock, and a *read* lock would take the storefront down every time an
administrator opened a form.

## Decision

The mechanism is chosen by **how long the critical section is**, not by how
valuable the data is.

### Microseconds, inside one request → pessimistic lock

`lockForUpdate()` inside `DB::transaction`, before the read that informs the
decision. This is CLAUDE.md's existing rule and it is unchanged: stock
reservation, stock release, and coupon redemption when it is built.

### An invariant spanning tables → lock the aggregate root

Every Action that can break the invariant takes `lockForUpdate()` on the same
row: the aggregate root, not the row it is about to write. For the product
invariant that row is `products`.

This makes the pair safe in both interleavings. Publish first, and removal
then sees an available product with one variation and refuses. Remove first,
and publishing then sees zero variations and refuses.

A lock order is declared and documented so composed Actions cannot deadlock:
**`products` before `inventories`**, and future Actions taking both acquire in
that order. `ReserveStock` and `ReleaseStock` take `inventories` alone and
never reach for a product, so no cycle exists today.

### Human think time, across requests → optimistic, and deferred

Not a lock. The mechanism is a version or `updated_at` carried through the
form and checked in the UPDATE's `WHERE` clause, with the second writer
refused and asked to re-read.

**It is deliberately not built.** `update_product` is administrator-only, the
reverted value is visible in the panel rather than silent, and no money or
authorization is at stake. The places where a lost update would genuinely
hurt — order status, stock corrections — are already covered by §19's history
rows and §20's ledger, which preserve the evidence rather than merely refusing
the write, and that is strictly more than optimistic locking buys.

The behaviour is pinned by tests that assert the defect, so it flips red the
day the mechanism is added.

### A test proves a lock only if it uses two connections

Single-process fault injection cannot test a lock: a row lock does not
constrain the transaction holding it, so an injected conflicting write on the
same connection succeeds with the lock in place. Such a test passes either
way, which by this project's standard makes it a decoration.

## Consequences

+ The three kinds of contention have three named mechanisms, so "is this
  contested state?" stops being one question with one answer.
+ A declared lock order means the first Action to lock two tables —
  `CreateOrder` — has a rule to follow rather than a problem to discover.
+ Locking the aggregate root generalises: orders and their items, coupons and
  their redemptions, carts and their items are the same shape.

− The aggregate-root lock serialises unrelated edits to one product. The cost
  is one row for one statement pair, and it is paid on every product write
  rather than only the ones that could conflict.
− Lost updates remain possible on every full-payload Filament form in the
  codebase, not just products. This ADR accepts that rather than fixing it.
− A third mechanism is a third thing to choose wrongly. The duration test is
  the tiebreaker: if the window spans a user's attention, a lock is the wrong
  tool no matter how important the row is.

## Alternatives rejected

- **Database triggers** enforcing the cross-table invariant. Consistent with
  no application code at all, and ADR-0004 already rejected them for putting a
  business rule where no test looks.
- **Locking `product_variations` instead of `products`.** The two Actions
  write different tables, so they would take different locks and neither would
  wait. Correct-looking and completely ineffective.
- **An advisory "being edited" lock** (a `record_locks` row with a heartbeat,
  as Drupal and WordPress do). Better UX for a large editorial team, and it
  needs a TTL, a heartbeat, and a steal-after-expiry path — without which a
  closed browser tab locks a product permanently, the same failure mode as an
  unreleased stock hold.
- **Sending only changed fields** instead of the whole form. Fixes disjoint
  edits, does nothing for two people editing one field, and can break a
  cross-field `CHECK` by validating each field against a snapshot the other
  half no longer matches. Measured, not assumed.
- **`SERIALIZABLE` isolation** for the whole application. Removes write skew
  without any explicit locking, at the cost of retry handling on every
  transaction in the system and a throughput loss paid on the many operations
  that were never contested.
