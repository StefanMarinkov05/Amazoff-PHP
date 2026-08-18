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

`--parallel` is the one to be careful with: each process needs its own test
database, so it only helps once `online_shop_test_1`, `_2` and so on exist.

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

`tests/Pest.php` applies `RefreshDatabase` to everything in `Feature`, which
truncates between tests but does **not** run seeders. A test that needs roles
or permissions seeds them itself:

```php
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});
```

`forgetCachedPermissions()` is not optional there. `spatie/laravel-permission`
caches the permission table for 24 hours, and `RefreshDatabase` does not clear
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
percentage says.** `tests/Concurrency/*` spawns real `php` subprocesses to
run the Action under test, and that code executes in a process PCOV never
instruments — verified: isolating just the race in
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

**57.5%** overall, full suite, measured 2026-08-17. `reference/coverage.md`
has the per-class breakdown, including which uncovered lines are proven by a
concurrency test PCOV can't see and which are genuinely untested.

## What the suite covers today

| File | Covers |
|---|---|
| `tests/Feature/FactoryTest.php` | Every factory persists a row — catches a factory writing a value its column cannot hold |
| `tests/Feature/RolePermissionTest.php` | The §37 criterion 18 access matrix, policy existence, and seeder idempotency |
| `tests/Unit/ExampleTest.php` | Framework placeholder, not yet replaced |
