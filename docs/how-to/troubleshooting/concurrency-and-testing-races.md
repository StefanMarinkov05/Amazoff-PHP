# Troubleshooting — concurrency tests and test-harness races

Part of the [troubleshooting index](../troubleshooting.md). Errors specific
to `tests/Concurrency/`, `RefreshDatabase`, and what happens when two
processes (or two `pest` runs) touch the same database at once.
`docs/explanation/concurrency-and-locking.md` covers what these tests can
and cannot prove.

---

## A concurrency test times out on its own first connection

**Symptom.** A test that opens a second database connection to exercise row
locking fails with `SQLSTATE[HY000]: General error: 1205 Lock wait timeout
exceeded` — and the timeout is raised by the *first* session, before the
second one has done anything worth blocking on.

**Cause.** `RefreshDatabase` wraps each test in a transaction and rolls it
back instead of committing. Rows the test inserted were therefore never
committed, so a second connection cannot see them — and when that connection
asks for one, it queues behind the test's own uncommitted write.

The failure looks like a locking bug in the code under test. It is the test
harness locking against itself.

**Fix.** Keep concurrency tests out of the refresh trait. `tests/Concurrency/`
is registered as its own suite in `phpunit.xml` and is excluded from the
`->use(LazilyRefreshDatabase::class)` binding in `tests/Pest.php`. Those tests commit
their fixtures and truncate in `afterEach`, with
`Schema::disableForeignKeyConstraints()` around the truncation so the order of
tables does not have to track whatever the factories currently create.

**Why it recurs.** `RefreshDatabase` is correct and invisible for every other
test in the suite, and nothing about a lock-wait timeout points at it. The
same trap is waiting for the coupon-redemption concurrency test, which is the
next piece of contested state after stock.

**Prevention.** Any test that needs a second connection to observe committed
state belongs in `tests/Concurrency/`. `explanation/concurrency-and-locking.md`
covers what those tests can and cannot prove.

---

## A concurrency test passes whether or not the lock is there

**Symptom.** A race test is green. Deleting `lockForUpdate()` from the code it
covers leaves it green.

**Cause.** Three variants of the same mistake, all of which look correct:

1. Taking the lock in the test with a raw `SELECT ... FOR UPDATE` and watching
   a second connection block. That exercises InnoDB, not the Action.
2. Holding the row elsewhere and asserting the Action raises 1205. The
   Action's `UPDATE` and its ledger `INSERT` block on the holder regardless of
   whether its `SELECT` took a lock, so the timeout arrives either way.
3. Asserting that exactly one of two racing requests succeeds. Both the locked
   and unlocked versions produce one winner — `chk_inventories_reserved_not_
   above_current` rejects the second write when the lock is absent.

There is also a timing failure underneath all three: Laravel takes a few
hundred milliseconds to boot and the window between the read and the write is
microseconds wide, so two sequentially started processes never overlap.

**Fix.** Assert the *kind* of failure rather than the fact of one. With the
lock the loser reads fresh state and raises the domain exception; without it
the loser reads stale state, passes the check, and is stopped by the database
constraint. `InsufficientStockException` against `QueryException` is the
signal.

Give the racing processes a barrier — a shared wall-clock instant both
spin-wait on after booting and warming their connection — so they enter the
critical section together. `tests/Concurrency/ReserveStockConcurrencyTest.php`
does this without any test-only branch in production code.

**Why it recurs.** Every one of the three variants produces a green test that
appears to be about locking, and a green test is not usually re-examined.

**Prevention.** A concurrency test is not finished until it has been observed
failing with the lock removed. Deleting the call, running the suite, and
restoring it takes a minute and is the only thing that distinguishes a guard
from a decoration.

---

## Every concurrency test fails at once, then passes on re-run

**Symptom.** The whole `tests/Concurrency/` suite fails together — currently
four tests — while every other test passes. Re-running the suite unchanged is
green. The failure message is the winner-count assertion, usually with
*neither* worker reporting success.

**Cause.** The barrier is a fixed wall-clock offset: `microtime(true) + 3.0`,
chosen to be generous for two cold Laravel boots. Under load the workers do
not finish booting and warming their connection before that instant passes, so
they never meet at the critical section — and a worker that arrives late
produces no output rather than a wrong one.

Observed while running Pint, Larastan and Pest against successive commits: a
`phpstan` run in the same container pushed the concurrency tests from ~10s to
~17s each, and one run failed all four. The count is the tell — *exactly* the
number of tests in the suite, all at once, is a harness problem, not a locking
one. A real regression fails one test with a specific wrong outcome.

**Fix.** Re-run without other work in the container. Nothing to change in the
code under test.

**Why it recurs.** The gap between "generous on an idle machine" and "enough
on a busy one" is invisible until something else is running, and CI is exactly
where something else is running. It will get worse as the suite grows, because
the barrier is per-test and the container is shared.

**Prevention.** Read the failure message before assuming a lock broke: the
assertions carry a hint distinguishing "neither won" (workers failed to boot)
from "both won" (the lock is genuinely gone). If this starts happening
regularly, raise the offset or derive it from a measured boot — do not delete
the barrier, which is what makes the interleaving happen at all.

---

## A concurrency test cannot be written in one process

**Symptom.** A test proving a `lockForUpdate()` works passes. Deleting the
lock leaves it passing. The test looks like it exercises the right window —
it deliberately interleaves a conflicting write between the Action's read and
its write, using a model event such as `saving` or `creating`.

**Cause.** A row lock constrains *other* transactions, never the one holding
it. Injecting the conflicting write on the same connection means it runs
inside the locking transaction, where the lock is not supposed to stop it and
does not. The test therefore behaves identically with the lock and without it.

**Fix.** Two real OS processes with a barrier, in `tests/Concurrency/`.
Both halves run as `php artisan race:worker`, spawned by `runRaceWorkers()`
in `tests/Concurrency/RaceHelper.php` — add a `match` arm to
`App\Console\Commands\RaceWorker::dispatchAction()` for the Action being
raced, then pass its job list. `ReserveStockConcurrencyTest` and
`PublishProductConcurrencyTest` are the two worked examples; the second
differs in asserting the winner *count*, which is possible only because no
`CHECK` constraint backs that invariant up.

**Why it recurs.** Fault injection is the right technique for the neighbouring
problem — proving a `DB::transaction` rolls back — and it works there for the
same reason it fails here: it runs inside the transaction under test.
`AddProductVariationTest` uses it correctly to prove a rollback. Copying that
pattern to a locking test is a natural and invisible mistake.

**Prevention.** Single-process fault injection proves a *boundary* exists. It
cannot prove a *lock* exists. If the mechanism under test is `lockForUpdate`,
the test needs a second connection, which means it needs a second process,
which means it belongs outside `tests/Feature`.

---

## A same-process collision test loses its injected row to the wrong rollback

**Symptom.** A test forces a unique-constraint collision by inserting a
conflicting row from inside a model event (`creating`) fired partway through
the Action under test. The Action's retry-on-violation logic should then pick
up that row and fold into it — but the row is gone by the time the Action
returns, as if the collision never happened.

**Cause.** The Action wraps its read-decide-write in `DB::transaction()`.
Laravel implements a transaction opened while one is already active (here,
the outer `RefreshDatabase` transaction) as a savepoint. The injected insert,
written through the query builder or even the raw PDO handle on the *same*
connection, still lands after that savepoint began — so when the unique
violation rolls the savepoint back, the injected row goes with it. The
collision is real for one statement and erased before the retry can see it.

**Fix.** None available in-process. This is a second variant of "A concurrency
test cannot be written in one process": there the problem was a lock
constraining only other transactions, here it is a savepoint undoing work
that looks like it happened on a different connection but shares the same one.
Prove the retry with two real processes instead —
`tests/Concurrency/AddToCartConcurrencyTest.php` is the worked example for
this Action.

**Why it recurs.** A raw PDO `exec()` looks like it should escape the ORM's
transaction tracking. It does not escape MySQL's: the connection, not the
framework, is what the savepoint rollback operates on.

**Prevention.** Any retry-on-`UniqueConstraintViolationException` mechanism
built on `DB::transaction()` needs a genuinely separate connection to test
with a single-process collision, or — the cheaper option every case in this
codebase has taken so far — a `tests/Concurrency/` test with two OS processes.

---

## Several unrelated tests fail at `UserSeeder`, then pass on re-run

**Symptom.** A handful of tests across unrelated files fail together, each
stack ending in `database/seeders/System/UserSeeder.php` with an SQLSTATE error.
Re-running the suite unchanged is green. Distinct from the "every concurrency
test fails at once" entry above: the failures here are scattered across Feature
files rather than confined to `tests/Concurrency/`, and the trace points at
seeding rather than at a winner-count assertion.

**Cause.** Two `pest` processes running against the same database at once —
typically one started with `run_in_background` and a second started in the
foreground before the first finished. `RefreshDatabase` migrates and seeds per
process, so the second run truncates tables the first is mid-way through
using, and whichever test is seeding when that happens fails on a row that
vanished underneath it.

**Fix.** Wait for the first run to finish. Nothing to change in the code.

**Why it recurs.** The full suite takes six to seven minutes, which is long
enough to be tempting to background, and long enough to forget it is still
running. Neither process reports that the other exists.

**Prevention.** One suite run at a time against a given database. If two are
genuinely needed, they need separate `DB_DATABASE` values, not separate
terminals.

---

## `pest --parallel` fails with `Access denied ... to database 'online_shop_test_test_N'`

**Symptom.** `SQLSTATE[HY000] [1044] Access denied for user 'sail'@'%' to
database 'online_shop_test_test_1'` (or `_2`, `_3`, …), only under
`--parallel`, on a Docker volume that has never run it before. `pest` without
`--parallel` works fine against the same volume.

**Cause.** `docker/mysql/init/01-test-database.sh` grants the app user access
to `online_shop_test` by name, once, on first container init. It predates
`--parallel` existing in this project, so the grant never covered the
per-process databases (`online_shop_test_test_1`, `_2`, …) Laravel creates on
demand for each paratest worker — the app user has no privilege to create or
touch a database it was never granted, wildcard or otherwise.

**Fix.** The init script now also grants a wildcard pattern,
`` `online\_shop\_test\_test\_%` ``, which covers any token paratest assigns
without listing them by hand. This only runs on a fresh volume, though — an
existing one needs the grant applied once by hand:

```bash
docker compose exec db mysql -u root -ppassword -e "GRANT ALL PRIVILEGES ON \`online\_shop\_test\_test\_%\`.* TO 'sail'@'%'; FLUSH PRIVILEGES;"
```

**Why it recurs.** Anyone who set up their dev volume before this grant
existed hits it the first time they try `--parallel`, no matter how long ago
their volume was created — the init script only ever runs once, at first
creation, so an old volume never picks up a later addition to it on its own.

**Prevention.** The wildcard grant is now permanent in the init script for
every new volume. If this reappears, the volume predates the grant — apply
the one-line fix above rather than debugging further; there is nothing else
this error means.

---

## `pest --parallel --testsuite=Concurrency` corrupts its own fixtures

**Symptom.** Run `Concurrency` tests under `--parallel` (or omit `--testsuite`
entirely while `--parallel` is on, which includes them by default) and a
large fraction fail — measured 21 of 33 — with `ModelNotFoundException` or a
raw `QueryException` surfacing from inside a race worker's captured output,
plus assertion-count mismatches like "expected size 1, actual size 0". The
same tests pass reliably run sequentially or under `--parallel
--testsuite=Feature`.

**Cause.** Laravel's automatic per-process test database
(`Illuminate\Testing\Concerns\TestDatabases::bootTestDatabase()`) only
switches a test case onto its own suffixed database
(`online_shop_test_test_N`) when that test case uses `RefreshDatabase`,
`DatabaseMigrations`, `DatabaseTransactions`, or `DatabaseTruncation`.
`tests/Pest.php` deliberately applies none of those to `Concurrency` — those
tests need a second real connection to see rows the first one already
committed, which any of those four traits' transaction-wrapping would hide.
The same exclusion that makes the tests correct under normal execution means
every parallel worker stays pointed at the one un-suffixed `online_shop_test`
database when running one, so two workers' fixtures — and their spawned race
workers' reads of those fixtures — collide in the same physical rows.

**Fix.** Don't. Run `Concurrency` sequentially, always: either bare `pest
--testsuite=Concurrency`, or CI's existing three hand-partitioned shards,
which already parallelise it correctly — one process, one database, one
sequential batch of files per shard, not one process per test.

**Why it recurs.** `--parallel` with no `--testsuite` filter silently includes
every suite, `Concurrency` among them, and the failure looks exactly like the
ordinary kind of concurrency-test flakiness the suite exists to distinguish
from a real race — someone re-running it expecting a transient collision
would burn real time before noticing every run fails the same way.

**Prevention.** Always pass `--testsuite=Feature` (optionally with `Unit`)
when using `--parallel`; never point it at `Concurrency` or leave
`--testsuite` unset. `run-the-tests.md`'s "Running in parallel" section
states this as the first rule, not a caveat at the bottom, for the same
reason.
