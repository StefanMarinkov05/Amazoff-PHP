# Contested state, and how it is protected

Some rows in this schema are read by one request to decide whether another
request may proceed. Stock is the first: §20 makes a reservation legal only if
`current − reserved` covers it. Coupon usage is the second, since §21 caps both
total and per-customer redemptions. Both are decisions made by reading a row
that another request may be changing at the same moment.

CLAUDE.md states the rule — `DB::transaction` **and** `lockForUpdate()` — and
this describes what each half does, what it prevents, and what it does not.

## The failure

Two customers check out on the last item. `current = 1`, `reserved = 0`.

```
        Request A                        Request B
t1      BEGIN
t2      SELECT → reserved = 0
t3                                       BEGIN
t4                                       SELECT → reserved = 0
t5      available = 1 ≥ 1, proceed
t6                                       available = 1 ≥ 1, proceed
t7      UPDATE reserved
t8      COMMIT
t9                                       UPDATE reserved
t10                                      COMMIT
```

Both read before either wrote, so both decided on state that was true when
read and false when acted on. This is the check-then-act pattern, and the
window between t2 and t7 is where every version of this bug lives.

## Why the transaction alone does not close it

MySQL's InnoDB runs at `REPEATABLE READ` by default. That level already
prevents two of the classical phenomena:

| Phenomenon | Meaning | Prevented at |
|---|---|---|
| Dirty read | Reading another transaction's uncommitted write | `READ COMMITTED` |
| Non-repeatable read | The same row read twice in one transaction differs | `REPEATABLE READ` |
| Phantom read | A range query gains rows on re-execution | `SERIALIZABLE`, largely `REPEATABLE READ` in InnoDB via gap locks |

None of them is the problem above. B never reads uncommitted data, never reads
the same row twice, and runs no range query. B reads **once**, correctly, from
a consistent snapshot — and the snapshot is stale by the time it writes.

That is the mechanism worth internalising: under `REPEATABLE READ` a plain
`SELECT` is a *consistent nonlocking read*, served from an MVCC snapshot taken
at the transaction's first read. It is guaranteed consistent. It is not
guaranteed current, and nothing about wrapping it in a transaction makes it so.

The anomaly has a name outside the ANSI list. Where the two transactions read
overlapping rows and write based on a predicate the other invalidates, it is
**write skew** — prevented only at `SERIALIZABLE`. Where both read the same
value, compute from it, and one write overwrites the other, it is a **lost
update** — prevented at no isolation level at all when the arithmetic happens
in application code.

## What the lock does

`lockForUpdate()` emits `SELECT ... FOR UPDATE`, a *locking read*. It differs
from a plain `SELECT` in two ways, and both matter:

- It takes an exclusive lock on the row, held until the transaction ends. A
  second `SELECT ... FOR UPDATE` on the same row blocks rather than returning.
- It bypasses the MVCC snapshot and reads the latest committed version.

Replaying the timeline, B blocks at t4, resumes after A commits at t8, reads
`reserved = 1`, and correctly finds nothing available. The decision is made on
current data because the read that informs it is serialised against the write
that invalidates it.

This is pessimistic locking: conflict is assumed and prevented by waiting. The
alternative, optimistic concurrency — a version column, retry on mismatch — is
not used here. Reservation conflicts are common on exactly the products worth
stocking, and a retry loop under contention is more machinery than a lock.

## Why the write is an increment

`increment()` is atomic; arithmetic in PHP is not.

```php
$inventory->increment('reserved_quantity', 1);
// UPDATE inventories SET reserved_quantity = reserved_quantity + 1 WHERE id = ?

$inventory->reserved_quantity += 1; $inventory->save();
// UPDATE inventories SET reserved_quantity = 1 WHERE id = ?
```

The first sends the *operation* to the database, which evaluates it against
committed state under its own exclusive lock — the value the request read
earlier never enters the arithmetic. The second sends a *literal* computed
from that earlier read, which may already be stale.

This does not replace the lock. The lock makes the availability decision
correct; the increment makes the write correct. What the increment buys is the
failure mode when the lock is absent:

```
increment()          B evaluates 1 + 1 = 2 at write time
                     chk_inventories_reserved_not_above_current rejects it
                     → QueryException, a 500 page. Nothing is oversold.

read-modify-write    A writes reserved = 1
                     B writes reserved = 1, from its own stale read of 0
                     → reserved = 1 for two orders. The constraint is
                       satisfied. The oversell is silent.
```

Both measured against the running database. The `CHECK` constraint from
ADR-0005 catches the first and cannot catch the second, because the second
produces a value that is internally consistent and simply wrong.

The counter-intuitive part is worth stating plainly: atomicity does not hide
the bug, it makes the bug trip a guard. A lost update writes a plausible
number; an atomic increment writes an impossible one.

## The layers, and what each is worth

| Layer | Guarantee |
|---|---|
| `chk_inventories_reserved_not_above_current` | Absolute. The database rejects the write regardless of application code |
| `SELECT ... FOR UPDATE` | Correct by construction. InnoDB's locking semantics are specified behaviour |
| `increment()` over PHP arithmetic | Keeps a lock failure visible instead of silent |
| The concurrency test | Evidence. A race is non-deterministic; a test makes the interleaving likely, never certain |

The ordering matters when judging a change. A test that stops reproducing the
race is a weaker signal than a constraint that is still in place, and the
constraint is why overselling is impossible rather than merely unlikely.

## Two other kinds of contention, which need different mechanisms

Stock is row contention inside a single request, and the lock above is the
right tool for it. Two other shapes appear in the catalogue, and applying the
same tool to either produces something wrong. ADR-0008 records the choice.

### An invariant spanning two tables

§6–7 requires every sellable product to have at least one variation.
`UpdateProduct` publishes a product after counting its variations;
`RemoveProductVariation` deletes a variation after checking the product is not
available. Both are check-then-act, and run concurrently they both read the
pre-state, both pass their own check, and both commit — leaving an available
product with nothing to sell.

This differs from stock in the layer that catches it: nothing does. Stock has
`chk_inventories_reserved_not_above_current`, so an unlocked race still fails,
merely as a 500 rather than a handled message. Here the rule spans `products`
and `product_variations`, which MySQL cannot express as a constraint, and
ADR-0004 rejected triggers. The unlocked race succeeds twice and the invariant
is simply gone.

The lock therefore goes on the **aggregate root** — the `products` row — taken
by both Actions, rather than on the rows each writes. Locking what each writes
would have them contend on different rows, waiting for nothing.

That difference shows up in what the two race tests can assert.
`ReserveStockConcurrencyTest` cannot count winners, because the `CHECK`
constraint produces exactly one either way; it asserts the *kind* of failure.
`PublishProductConcurrencyTest` counts winners, because with no constraint
underneath, two winners is precisely what the missing lock produces.

### A lost update across two requests

Two employees open a product's edit form. Each changes one field and saves.
The second silently reverts the first's change to a field the second never
touched.

The mechanism is Eloquent's dirty checking measuring against a baseline that
moved. Livewire re-resolves the record from the database when the request
hydrates, so at save time the model's originals are current while the
submitted payload still carries everything the form loaded. An untouched field
now differs from its original, counts as dirty, and lands in the `UPDATE`.

Two plain Eloquent instances both read before either wrote do *not* show this
— there the stale value matches its own original and is never dirty. The bug
therefore appears through the panel and not in the obvious test, which is why
it is worth stating as mechanism rather than as advice.

No lock closes it. The window is the time a human spends looking at a form,
and a PHP request ends when that form is rendered, so no transaction survives
to the submission. It needs optimistic concurrency: a version or `updated_at`
carried through the form and checked in the `UPDATE`'s `WHERE` clause.

### The rule that separates them

The mechanism follows the length of the critical section, not the importance
of the data.

| Window | Mechanism |
|---|---|
| Microseconds, one request | pessimistic lock |
| Microseconds, one request, invariant across tables | pessimistic lock on the aggregate root |
| Human attention, across requests | optimistic — version check, refuse, re-read |

A lock held across a user's think time is not a lock, it is an outage. That is
the same reason stock is not held from the moment a customer opens checkout.

## Deadlock, once an order holds more than one line

Nothing in the codebase reserves two variations yet. `CreateOrder` will, and
two orders locking the same pair in opposite orders deadlock:

```
Order A   locks variation 7, waits for 9
Order B   locks variation 9, waits for 7
```

InnoDB detects the cycle and rolls one transaction back with error 1213. The
standard avoidance is a deterministic lock order — sorting the lines by
primary key before locking, so no two requests can acquire in opposite
sequence. Retrying a deadlocked transaction is the fallback, not the fix.

## How this is tested

Every race test in `tests/Concurrency/` is built the same way, and the shape is
forced by three facts.

**Two real OS processes.** The race is between two *connections*. One PHP
process holds one connection, so it cannot produce the interleaving no matter
how the test is written — a second connection means a second process.

**A barrier.** Booting Laravel takes a few hundred milliseconds and varies run
to run; the window under test is microseconds wide. Started sequentially the
second worker reliably arrives after the first has committed, and the test then
behaves identically with and without the mechanism. So both workers boot, warm
their connection, and spin-wait on a shared wall-clock instant. The barrier
lives entirely in the test: no flag, no sleep, and no test-only branch in
production code. It must be generous enough for two boots on a *loaded*
machine, because a barrier that stops aligning makes the workers sequential —
which is the failure mode that passes.

**Outside `RefreshDatabase`.** It wraps each test in a transaction that is
rolled back rather than committed, so rows the test created are invisible to
the second connection — and asking for one makes that connection queue behind
the test's own uncommitted write. These tests commit their fixtures and
truncate afterwards.

### Choosing the assertion

This is the step that decides whether the test is worth anything, and it
differs per mechanism. Ask what catches the failure if the mechanism is gone:

| What backs the invariant | Unlocked outcome | Assert |
|---|---|---|
| A `CHECK` or `UNIQUE` constraint | still exactly one winner; only the failure differs | the *type* of the loser's exception |
| Nothing — the rule spans tables | both writes commit | the winner *count*, and the invariant on final state |
| Nothing to delete — a blind single-statement write | unchanged | the final state, as a property rather than a guard |

Stock is the first row: `chk_inventories_reserved_not_above_current` produces
one winner either way, so counting winners proves nothing and
`InsufficientStockException` against `QueryException` is the signal. The
product invariant is the second: MySQL cannot express it across two tables, so
without the lock both processes genuinely succeed. Promoting a main image is
the third: it reads nothing to decide anything, so there is no window and no
mechanism to remove.

Getting this backwards produces a green test that survives deleting the lock.

### What cannot substitute for a second process

Single-process fault injection. A row lock places no constraint on the
transaction that holds it, so a test that injects a conflicting write on its
own connection passes with the lock and without it. It can demonstrate that a
window exists between a read and a write; it cannot demonstrate that anything
closes the window — that is the right technique for proving a transaction
rolls back, and the wrong one for proving a lock exists.

`how-to/troubleshooting.md` records the designs that look correct and prove
nothing, and why the suite fails as a block under load.
`reference/concurrency-coverage.md` lists every contested resource, its
mechanism, and the test that has been observed failing without it.
