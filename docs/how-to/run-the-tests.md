# How to run the tests

Everything runs inside the `app` container. Running Pest from the host fails —
`vendor/` is installed in the image, not on the host, which is the same reason
the IDE reports Laravel classes as undefined.

For what CI does with these commands and how to reproduce a CI-only failure,
see `use-ci.md`.

## The whole gate stack

The three checks a pull request has to pass, in the order CI runs them:

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest
```

`pint --test` reports formatting violations without fixing them. Drop
`--test` to apply the fixes:

```bash
docker compose exec app ./vendor/bin/pint
```

Run the formatter before pushing to any branch, not only before opening a pull
request. CI only runs on pull requests targeting `main`, so a chain of feature
branches merging into each other is unchecked until the final hop — see the
"A failing Pint check reaches `main` anyway" entry in `troubleshooting.md`.

## A single file or a single test

```bash
# one file
docker compose exec app ./vendor/bin/pest tests/Feature/RolePermissionTest.php

# every test whose name matches, across all files
docker compose exec app ./vendor/bin/pest --filter=RolePermission
docker compose exec app ./vendor/bin/pest --filter="denies a customer"
```

`--filter` matches the test description, not the filename, so it also selects
individual cases from a dataset.

## Useful flags

| Flag | Effect |
|---|---|
| `--bail` | Stop at the first failure instead of running the rest |
| `--dirty` | Only files changed since the last commit |
| `--retry` | Re-run the tests that failed last time, then the rest |
| `--parallel` | Split across processes — needs a database per process |
| `--compact` | One line per file rather than per test |

`--parallel` is the one to be careful with — see "Running in parallel" below
for what it actually requires and where it must not be pointed.

## Running in parallel

Requires `brianium/paratest` as a dev dependency:

```bash
docker compose exec app composer require --dev brianium/paratest
```

Then scope it to `Feature` (and `Unit`, which is fast enough that it barely
matters either way):

```bash
docker compose exec app ./vendor/bin/pest --parallel --processes=4 --testsuite=Feature
```

**Never run `Concurrency` under `--parallel`, and never omit `--testsuite` while
`--parallel` is on** — measured on this codebase: `pest --parallel --testsuite=Concurrency`
produces 21 failures out of 33 tests, all `ModelNotFoundException` or
`QueryException` from a race worker reading rows another process had already
deleted. This is not flakiness to retry away.

Laravel's automatic per-process test database (`online_shop_test_test_1`,
`_2`, …) is wired up in `Illuminate\Testing\Concerns\TestDatabases`, and it
only fires for a test case using `RefreshDatabase`, `DatabaseMigrations`,
`DatabaseTransactions`, or `DatabaseTruncation` — checked via
`class_uses_recursive()`, so `LazilyRefreshDatabase` (`Feature`'s actual
trait; it `use`s `RefreshDatabase` internally) still qualifies.
`tests/Pest.php` deliberately applies none of the four to `Concurrency` —
see the comment there: those tests need a second real connection to see
rows the first one committed, which a wrapping transaction would hide. That
same exclusion is what leaves every parallel worker pointed at the one
un-suffixed `online_shop_test` database when a Concurrency test runs, so two
workers' fixtures collide in the same physical rows. `Feature` tests survive
this same mechanism failing open only because their trait already isolates
them by transaction; `Concurrency` tests have no such isolation by design,
so they need the database-per-process split and get skipped by it at once.

CI already parallelises `Concurrency` correctly, at the process level rather
than the row level: three hand-partitioned shards (`test-concurrency` a/b/c
in `ci.yml`), each running its own sequential batch of files in its own
job/database. Reproduce that locally by running each shard's file list as
its own sequential `pest` invocation in a separate terminal, if the full
Concurrency suite's ~12 minutes needs cutting down — don't reach for
`--parallel` for it.

**Measured on this machine** (12 cores, `nproc` inside the `app` container),
`Feature` + `Unit`, 472 tests:

| Invocation | Duration |
|---|---|
| Sequential (`pest --testsuite=Feature`) | 473.75s |
| `--parallel --processes=4` | 259.69s |
| `--parallel --processes=12` (= `nproc`) | 338–361s |

`--processes=12` is *slower* than `--processes=4`, not faster: every worker
pays its own `migrate:fresh` — including the `database/schema/mysql-schema.sql`
load, ~55s alone in the sequential run — on every invocation regardless of
whether that worker's database already existed from a prior run (Laravel
reuses the database but still re-runs the migration against it). Twelve
workers doing that at once against one shared MySQL container are
I/O-contending with each other during the expensive part; four are not.
Re-measure if the machine or the schema's migration cost changes materially
— this is a measured number for this codebase's current size, not a
universal constant.

A fresh Docker volume needs one extra grant before any of this works — see
`troubleshooting.md`.

## Which database the tests use

`phpunit.xml` forces `DB_CONNECTION=mysql` and `DB_DATABASE=online_shop_test`,
so a test run cannot touch development data no matter what `.env` says. Host,
port, and credentials still come from the environment.

The suite runs on MySQL rather than SQLite in memory because SQLite ignores
`VARCHAR` lengths, stores `enum` columns as free text, and silently skips the
migration that adds the 45 `CHECK` constraints — the guarantees from ADR-0005
would be absent for the entire suite while it stayed green. ADR-0005 and
`use-ci.md` record the full reasoning.

If `pest` fails with `Access denied ... to database 'online_shop_test'`, the
database was never created locally. The fix and why it only affects older
Docker volumes are in `troubleshooting.md`.

## Seeding inside a test

`tests/Pest.php` applies `LazilyRefreshDatabase` to everything in
`Feature` — the same isolation as `RefreshDatabase` (truncates between
tests, does **not** run seeders), except migration is deferred until a
test's first database touch rather than always running. A test that needs
roles or permissions seeds them itself:

```php
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});
```

`forgetCachedPermissions()` is not optional there. `spatie/laravel-permission`
caches the permission table for 24 hours, and neither refresh trait clears
that cache — without it the second test in a run resolves permissions against
the first test's deleted rows.

## Checking that a test can fail

A test that has never been observed failing is not evidence of anything. After
writing one, break the thing it covers and confirm it goes red:

```bash
# delete a policy, run the suite, expect a failure, restore it
mv online-store/app/Policies/CarrierPolicy.php /tmp/
docker compose exec app ./vendor/bin/pest --filter="resolves a policy"
mv /tmp/CarrierPolicy.php online-store/app/Policies/
```

This matters most for authorization tests. An assertion that a role *can* do
something passes just as happily against a system with no authorization at
all, so the assertions carrying information are the denials.

## Coverage

```bash
docker compose exec app ./vendor/bin/pest --coverage
docker compose exec app ./vendor/bin/pest --coverage-html=coverage-html
```

PCOV — coverage collection only, chosen against a suite that
already pays real process-boot overhead in `tests/Concurrency/`. ADR-0009 has
the full reasoning, including why this is a report rather than a CI gate: no
`--min` threshold anywhere, nothing fails the build over a percentage.

**A race contributes nothing to this number, whatever the class's overall
percentage says.** `tests/Concurrency/*` spawns real `php artisan
race:worker` subprocesses to run the Action under test, and that code
executes in a process PCOV never instruments — verified: isolating just the
race in
`PublishProductConcurrencyTest.php` measures `UpdateProduct` and
`RemoveProductVariation` at 0.0%. But a class's own file often *also*
carries a same-process test (a sequential companion, or a separate feature
test file), so the overall percentage can still read high — isolating
`ReserveStockConcurrencyTest.php`'s sequential companion test alone measures
`ReserveStock` at 92.3%. Read the number as silent about the race
specifically, not as evidence either way about whether the interleaving
itself was exercised — `reference/write-rules/concurrency.md` is what actually
proves that, by deletion. ADR-0009 has the full reasoning.

`coverage-html/` is gitignored; open `coverage-html/index.html` after
generating it.

The full suite needs more than PHP's default 128M `memory_limit` to assemble
the report — measured: it exhausted 128M building `coverage.php` after all
418 tests had already passed. `docker/php/conf.d/cli-memory.ini` raises it to
1G; nothing extra to pass on the command line.

**55.9%** overall, full suite, measured 2026-08-23. `reference/coverage.md`
has the per-class breakdown, including which uncovered lines are proven by a
concurrency test PCOV can't see and which are genuinely untested.

## What the suite covers today

| File | Covers |
|---|---|
| `tests/Feature/FactoryTest.php` | Every factory persists a row — catches a factory writing a value its column cannot hold |
| `tests/Feature/RolePermissionTest.php` | The §37 criterion 18 access matrix, policy existence, and seeder idempotency |
| `tests/Unit/ExampleTest.php` | Framework placeholder, not yet replaced |
