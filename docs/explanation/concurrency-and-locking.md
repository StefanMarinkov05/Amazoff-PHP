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

## Two different failures, depending on how the write is expressed

The Actions use `increment()`, which compiles to
`SET reserved_quantity = reserved_quantity + 1`. That is not a stylistic
choice, and the difference is larger than it looks.

An `UPDATE` re-reads the row at write time under its own exclusive lock, so
`reserved + 1` is computed from committed state rather than from what the
request read earlier. With the lock removed, the consequence is:

```
increment()          B computes 1 + 1 = 2 at write time
                     chk_inventories_reserved_not_above_current rejects it
                     → QueryException, a 500 page. Nothing is oversold.
```

Had the arithmetic been done in PHP — read 0, add 1, write the literal 1 —
both requests would write the same value:

```
read-modify-write    A writes reserved = 1
                     B writes reserved = 1
                     → reserved = 1 for two orders. The constraint is
                       satisfied. The oversell is silent.
```

Verified against the running database. The `CHECK` constraint from ADR-0005
catches the first and cannot catch the second, because the second produces a
value that is internally consistent and simply wrong.

So the lock is what produces a *good* failure, and `increment()` is what keeps
the bad case loud rather than silent. Neither substitutes for the other.

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

`tests/Concurrency/` is a separate suite because `RefreshDatabase` wraps each
test in a transaction that is rolled back rather than committed, and rows that
were never committed are invisible to a second connection.

The race test runs two real OS processes. Both boot, warm a database
connection, and spin-wait on a shared wall-clock instant, because Laravel takes
a few hundred milliseconds to start and the window under test is microseconds
wide — sequentially started processes never overlap.

The assertion is the *type* of the loser's exception, not the winner count.
Both the locked and unlocked versions produce exactly one winner; only the
locked version lets the loser find out by reading rather than by having the
database reject its write. Deleting `lockForUpdate()` turns
`InsufficientStockException` into `QueryException`, and that is what the test
detects.
