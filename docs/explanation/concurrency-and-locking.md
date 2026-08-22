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

## What a blocked transaction actually experiences

Three outcomes, not one, and only the third and rarest is an exception at the
call site.

**It waits, and resolves.** The ordinary case, above: `SELECT ... FOR UPDATE`
blocks until the row's current holder commits or rolls back, then proceeds
against the now-current row. No exception, no error — the request is simply
slower. This is what every `lockForUpdate()` call in this codebase is built to
produce, and the concurrency suite's whole job is proving it does.

**It waits too long.** If whatever holds the lock never finishes — a hung
connection, a request that stalled, a bug — MySQL gives up after
`innodb_lock_wait_timeout` (50s by default) and raises error 1205, "Lock wait
timeout exceeded." Not something this codebase has hit; short, single-purpose
Actions rarely hold a lock for anywhere near 50 seconds.

**It deadlocks.** Two transactions each waiting on a lock the other holds — see
"Deadlock, once an order holds more than one line" below for the shape and how
it's avoided here. Verified against Laravel's own source rather than assumed:
`Illuminate\Database\Connection::transaction()` runs every exception through
`causedByConcurrencyError()`, which pattern-matches both the 1205 and 1213
messages identically — so a lock-wait-timeout and a deadlock are handled the
same way once they reach PHP. What that handling produces depends on nesting.
At the top level (`$this->transactions === 1`, true for every single-Action
call in this codebase — checked, none of `app/Actions/*`'s `DB::transaction()`
calls pass a retry count) it rolls back and rethrows the original
`QueryException` — no automatic retry, since Laravel only retries when
`DB::transaction($callback, $attempts)` is called with `$attempts > 1`, which
nothing here does. Only when the failure happens on a **nested** transaction —
one Action's `DB::transaction()` running as a savepoint inside another's, per
ADR-0007's composition — does Laravel instead throw a distinct
`Illuminate\Database\DeadlockException` (still a `PDOException`, not a
`QueryException`) so the outer transaction knows the savepoint, not the whole
transaction, failed. Nothing in this codebase catches either type specially
today; both are uncaught all the way up.

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

## A savepoint does not refresh the snapshot

`AddToCart`'s retry-on-collision (catch `UniqueConstraintViolationException`,
retry as an update) works because the retry is a brand-new top-level
`DB::transaction()` call, with its own fresh `REPEATABLE READ` snapshot taken
at its own first read. `MergeGuestCart` composes several such attempts inside
one outer transaction, per ADR-0007's "Composition nests" — each line's own
`DB::transaction()` becomes a savepoint rather than a new transaction, which
is what lets an uncaught failure on one line roll back every line already
merged in the same call.

That composition has a consequence worth naming, because it is not obvious
and it broke the first version of `MergeGuestCart`'s fix: **rolling back to a
savepoint does not move the outer transaction's snapshot.** MySQL takes a
`REPEATABLE READ` snapshot once, at the transaction's first read, and every
plain `SELECT` inside that transaction — including one issued after a
savepoint rollback — is served from it. A retry that re-reads plainly still
sees the pre-collision state and collides again, with nothing left to catch
the second failure.

The fix is the same tool `ReserveStock` and `ReleaseStock` already use for a
different reason: `lockForUpdate()` bypasses the snapshot and reads the
latest *committed* row regardless of when in the transaction it runs. The
retry locks; the first attempt does not, because a lock cannot prevent a
collision on a row that does not exist yet — see `AddToCart`'s own docblock
for why that Action rejects a lock as the mechanism in the first place.

The general shape: a nested `DB::transaction()` gets a consistent view of
*writes*, via the savepoint, but not a fresh view of *reads*, because the
snapshot belongs to the outer transaction and nothing inside a nested call
can renew it. Any retry-after-catch pattern written inside a composed Action
needs to ask which of the two it needs.

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

`CreateOrder` reserves every line of a multi-item order, and
`TransitionOrderStatus` locks `inventories` again for each line on
`=> Cancelled`/`=> Shipped`/`=> Returned`. Two orders — or two transitions —
locking the same pair of variations in opposite orders deadlock:

```
Order A   locks variation 7, waits for 9
Order B   locks variation 9, waits for 7
```

InnoDB detects the cycle and rolls one transaction back with error 1213 — what
that produces in PHP is "What a blocked transaction actually experiences"
above. The standard avoidance is a deterministic lock order — sorting the
lines by primary key before locking, so no two requests can acquire in
opposite sequence. Retrying a deadlocked transaction is the fallback, not the
fix.

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

### The worker is one Artisan command, not a generated script

Both halves of a race run as `php artisan race:worker <action>`, spawned by
`runRaceWorkers()` in `tests/Concurrency/RaceHelper.php`. The command
(`app/Console/Commands/RaceWorker.php`) owns the bootstrap, the connection
warm-up, the barrier, the optional rendezvous, and the
`OK`/`FAILED:<exception class>` protocol the assertions read. Each test
supplies only what differs: which action, which model ids, which scalar
arguments.

Until slice 6c each test instead built its worker as a PHP nowdoc, wrote it
to `base_path()` with `file_put_contents()`, spawned `php <name>.php`, and
`@unlink()`ed it in a `finally`. That kept the worker colocated with the
assertions reading its outcome, at the cost of duplicating the
Laravel-bootstrap preamble — `require autoload.php`, boot the kernel,
`DB::select('SELECT 1')`, the busy-wait barrier — across twelve files, and
of leaving an untracked file in the project root whenever a run died before
its `finally` (a fatal mid-spawn, a killed test process).

What the command form gives up, and why it is acceptable: the exact call a
worker makes is now one `match` arm away rather than inline in the test.
Auditing a race still means reading the test's job list — action name, ids,
args — and one short arm in `dispatchAction()`, which is type-checked by
Larastan in a way a nowdoc string never was. Colocation was the original
justification and it did not require the bootstrap to be duplicated to
obtain.

Measured on the change itself: the full `Concurrency` suite went from ~695s
to 518s, because Artisan's bootstrap is cheaper than the hand-rolled
`require bootstrap/app.php` each worker was doing.

The `DB_*` environment still has to be passed explicitly to every spawned
worker (`raceWorkerEnvironment()`), for the same reason as before — a child
process reads `.env`, not `phpunit.xml`, so without it a race silently runs
against the *development* database. That is also what makes each worker
follow paratest's per-worker database when one is active, though
`Concurrency` must not be run under `--parallel` for a separate reason:
`how-to/run-the-tests.md`, "Running in parallel".

### A cross-Action race needs a fourth thing: a rendezvous

The three facts above are enough when both processes run the *same* Action —
add racing add, merge racing merge. Identical code takes identical time to
reach the critical section, so aligning process *start* times is the same as
aligning *arrival* times, and either side is equally likely to lose.

It is not enough when the two processes run *different* Actions.
`AddToCart` and `MergeGuestCart` do different amounts of work before their
own read-then-insert — `MergeGuestCart` validates nothing first,
`AddToCart` checks `min_order_quantity` and `available()` — so a wall-clock
barrier that successfully aligns both *starts* can still leave one side
reliably arriving at its insert first. Measured, not assumed:
`AddToCartVsMergeGuestCartConcurrencyTest.php` raced the pair 24 times under
three synchronization strategies, and `MergeGuestCart` won every single one.
That is a real property of the two code paths, not a flaw in the barrier.

The fix is a second, tighter synchronization layered on top of the
wall-clock one: each worker writes its own ready-flag file once it reaches
the barrier, then polls for the other's flag before calling its Action, so
neither proceeds until both have arrived. This removes process-boot jitter
specifically — it cannot equalise the two Actions' own internal work, only
the time it took each process to get to the starting line.

In code this is `'rendezvous' => '<name>'` on **both** jobs passed to
`runRaceWorkers()`; the helper pairs each side with the other's flag file
and `race:worker` does the handshake. One-sided is a mistake that blocks
until its two-second timeout and then proves nothing — the pairing is what
makes it a rendezvous.

A cross-Action test should also race more than once — `->repeat(n)` in
Pest — since a single run of "delete one side's mechanism, check once" can
pass by chance if that side happens to win. It is still not sufficient proof
if the asymmetry turns out to be as deterministic as this one was; only a
technique that can force the losing side to alternate would be.

**Whether the rendezvous is enough, on its own, is answered both ways by the
two cases measured so far — it depends on the pairing, not the technique.**
`DeleteProductCategoryConcurrencyTest.php` raced `DeleteProductCategory`
(lock, two counts, a delete) against a plain category insert. Without the
rendezvous the insert won every time — the exact same boot-jitter trap,
independently rediscovered. *With* it, both sides won a real share (roughly
2:1), because the two operations' internal work, while unequal, was close
enough that removing boot jitter was sufficient on its own. `AddToCart` vs
`MergeGuestCart` needed more than that — the gap between "validates nothing"
and "checks two things first" apparently did not close the same way. Neither
result generalises to the other: measure the specific pairing before
concluding the rendezvous did or didn't work, rather than assuming from
either precedent. `reference/write-rules/cart.md`'s "Known gaps" has the
cart case in full; `reference/write-rules/product-category.md`'s "Known
gaps" has this one.

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

### A fourth shape: idempotent no-ops

None of the three rows above fit two identical concurrent requests against an
Action whose repeat is a designed no-op rather than a refusal.
`TransitionOrderStatus` is the case: `$from === $to` returns the order
unchanged rather than throwing, because a double-submitted status change is
not an error. Racing two identical transitions against one order therefore
does not produce a winner and a refused loser — it produces **two successes**,
because the second call's lock-serialized re-read finds the order already at
its target and takes the no-op path deliberately, not by accident.

What proves the lock is doing anything here is not what either process
observes — both report success either way if the no-op path is correct — but
that the *write* happened exactly once: one `order_status_histories` row, not
two, and no `QueryException` off `UNIQUE(order_id, new_status)`. Getting this
backwards — asserting a winner count, as the first three shapes would suggest
— produces a test that fails against entirely correct behaviour, which is a
worse mistake than the usual "asserts nothing." `tests/Concurrency/
TransitionOrderStatusConcurrencyTest.php`, "makes two identical concurrent
transitions idempotent" is the example; its sibling test, racing two
*different* legal targets from the same origin, is back to the ordinary
loser-gets-refused shape, because that pair has no legitimate reading in
which both calls should succeed.

### Trusting the locked row, not the reference that was locked

`ReserveStock`/`ReleaseStock`/`CompleteSale`/`RestockReturn` never have this
problem, because they read the quantities they act on directly off the
locked row in the same statement. `TransitionOrderStatus` can, because it is
handed an `Order` *instance* — `handle(Order $order, OrderStatus $to, ...)` —
that was hydrated before the lock was ever requested, and the natural-looking
`$order->status` is sitting right there once the lock resolves.

Reading it is wrong. `Order::query()->lockForUpdate()->findOrFail($order->
getKey())` and evaluating `->status` on *that* result is the load-bearing
step — the parameter's own `status` reflects whatever the database held at
hydration time, unrelated to whether this call is now holding the lock after
waiting behind another writer. A lock taken correctly but then ignored in
favour of a stale reference protects nothing, and nothing about the code
announces this: it type-checks, it reads naturally, and a single-process test
cannot tell the two apart, because there is nothing else writing to disagree
with. `tests/Concurrency/TransitionOrderStatusConcurrencyTest.php`'s first
test is deletion-proofed against exactly this — swapping the locked row's
`status` for the parameter's turns the identical-transition race from "both
succeed, one write" into a `UniqueConstraintViolationException` on the
second call, since it recomputes the transition as though nothing had
changed and tries to insert a second `order_status_histories` row for a
`(order_id, new_status)` pair that already exists.

### What cannot substitute for a second process

Single-process fault injection. A row lock places no constraint on the
transaction that holds it, so a test that injects a conflicting write on its
own connection passes with the lock and without it. It can demonstrate that a
window exists between a read and a write; it cannot demonstrate that anything
closes the window — that is the right technique for proving a transaction
rolls back, and the wrong one for proving a lock exists.

`how-to/troubleshooting.md` records the designs that look correct and prove
nothing, and why the suite fails as a block under load.
`reference/write-rules/concurrency.md` lists every contested resource, its
mechanism, and the test that has been observed failing without it.
