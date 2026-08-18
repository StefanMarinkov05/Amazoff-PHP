# How to use CI

`.github/workflows/ci.yml`. Nothing to run — it triggers on its own.

## When it runs

Any push to `main`, and **any** pull request regardless of its target
branch. Pushing to a feature branch on its own does not trigger it; opening
a PR from that branch does.

The trigger was previously scoped to PRs targeting `main`, which left a gap:
a chain of branches merging into each other — `lookup-resources` into
`feature` into `development` into `main` — only ran CI on the final hop. The
earlier PRs showed "no checks reported", which reads as clean and means
untested. A formatting failure reached `main` that way.

## Where to see it

On a PR, in the checks section near the bottom, listed as "CI / lint",
two "CI / test (1/2)" shards, and three "CI / test-concurrency (a/b/c)"
shards — six jobs, running in parallel. The repo's **Actions** tab has the
full run history.

## What it does, in order

Six jobs. `test` and `test-concurrency` each have their own MySQL 8
service container per shard (services are not shared across jobs or
matrix shards); `lint` needs no database. All run in parallel rather than
one after another.

**`lint`** — no database needed:

1. Checks out the code.
2. Installs PHP 8.4, no coverage driver.
3. `composer install`.
4. Runs `pint --test`.
5. Runs `phpstan analyse` (Larastan).

**`test`** — a 2-shard matrix over `tests/Unit` and `tests/Feature`:

1. Checks out the code.
2. Installs PHP 8.4 with the extensions the app needs
   (`mbstring, pdo_mysql, bcmath, gd, zip, intl, exif`), no coverage driver.
3. `composer install` (no npm/build step — nothing under `tests/Unit` or
   `tests/Feature` touches a compiled asset or calls `@vite`/`Vite::` /
   `mix()`, verified by grep before removing the step).
4. Copies `.env.example` to `.env`, generates an app key.
5. Runs `php artisan migrate --force`.
6. Runs `pest` against that shard's file list.

**`test-concurrency`** — a 3-shard matrix, each shard running a fixed
subset of `tests/Concurrency`:

1. Checks out the code.
2. Installs PHP 8.4 with the same extensions, no coverage driver.
3. `composer install` (no npm/build step — these tests call Actions
   directly, no HTTP or Livewire rendering involved).
4. Copies `.env.example` to `.env`, generates an app key.
5. Runs `php artisan migrate --force`.
6. Runs `pest` against that shard's file list.

Within a job, any step failing turns that job (or that shard) red and
stops it — later steps do not execute. Jobs and shards don't block each
other.

### Why `test` and `test-concurrency` are sharded

`tests/Concurrency/*` spawns real `php` subprocess pairs synchronized on a
wall-clock barrier (`explanation/concurrency-and-locking.md`) — each test
pays several seconds of process-boot and barrier-wait overhead that a
Feature test doesn't. Measured on a full run: 9 concurrency test files took
about as long as the other ~30 test files combined. Running them in a
parallel job instead of after the fast suite doesn't reduce that time, but
takes it off the same critical path.

Within each suite, time is not evenly spread, and the two suites are
uneven for different reasons — ADR-0010 has the full measured numbers and
the false leads ruled out along the way:

- In `tests/Concurrency`, one file (the only one using `->repeat(6)`) is
  over half the suite's own wall-clock time on its own — a fixed cost of
  the synchronization mechanism itself, paid per repeat.
- In `tests/Unit`+`tests/Feature`, one file —
  `tests/Feature/RolePermissionTest.php` — is over a third of the suite's
  time on its own, for an unrelated reason: its `beforeEach` reseeds
  `PermissionSeeder`, `RoleSeeder`, and `UserSeeder` before *every* test
  rather than once per file (deliberate — the permission registrar caches
  for 24h, and without forgetting it between tests the second test
  resolves against the first test's already-truncated rows).

Both suites' shards are hand-partitioned against these measurements, not
split evenly by file count — an even split would still strand the one
dominant file alone in whatever shard it landed in.

**Adding a new test file**: for `tests/Concurrency`, add it to shard b or
c (the two lighter shards) unless you already know it will be slow — a
`->repeat()` call, several assertions per test, or a workload closer to
`AddToCartVsMergeGuestCartConcurrencyTest` than a single race. For
`tests/Unit`/`tests/Feature`, add it to shard 2 unless it shares
`RolePermissionTest`'s per-test reseeding pattern, in which case shard 1.
Re-balance (or give a file its own shard) once one shard's
`gh run view <id> --log` time visibly outruns the others by more than its
fair share — this is optimizing a number nobody watches per-commit, not
something to re-measure on every PR.

No job in this workflow collects coverage. It was dropped from CI
entirely rather than merged across shards or kept on one shard only — see
ADR-0009 and ADR-0010 for why. Generate it locally with `pest --coverage`
(`docs/reference/coverage.md` has the commands) when the numbers are
actually needed.

## Why Pest runs against the MySQL service

`online-store/phpunit.xml` sets `DB_CONNECTION=mysql` and
`DB_DATABASE=online_shop_test`. Host, port, and credentials come from the
environment, so the same file works in CI and in Docker locally, and only the
database name is overridden — a test run cannot touch development data.

It used to run against SQLite in memory, which was faster and wrong. SQLite
ignores `VARCHAR` lengths, keeps `enum` columns as free text, and has no
`ALTER TABLE ADD CONSTRAINT`, so the migration adding the 45 `CHECK`
constraints (ADR-0005) skipped itself and none of them existed during a test
run. A factory writing past a `varchar(60)` passed every time.

The cost that normally argues for SQLite — a slow `migrate:fresh` — turned out
to be the database container's durability settings rather than MySQL itself.
See the entry in `troubleshooting.md`.

## Reproducing a CI failure locally

Same commands the workflow runs, through Docker:

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest
```

`pest` with no path runs everything, every shard of `test` and
`test-concurrency` combined. To reproduce one shard, use its file list
from `.github/workflows/ci.yml`'s `strategy.matrix.shard`; `pest
tests/Unit tests/Feature` alone reproduces both `test` shards together,
and `pest tests/Concurrency` alone reproduces all three
`test-concurrency` shards together.

`pint --test` only checks formatting and reports violations — it does not
fix them. Run `./vendor/bin/pint` (no `--test`) to actually apply the fixes,
then re-run `--test` to confirm.

## What a green check does and does not mean

Green means Pint, Larastan, and Pest (all shards of `test` and
`test-concurrency`) all passed.

It does **not** block a merge, and on this repository it cannot.

Blocking a merge on a status check requires a branch protection rule or a
ruleset. GitHub offers neither for a **private repository on a free
organisation plan** — the API answers
`Upgrade to GitHub Pro or make this repository public to enable this feature`
for both. It is also an admin-level setting, and both developers hold
`write` rather than `admin`.

So a red check here is visible and advisory. Nothing stops a merge on top of
it, which is how PR #22 landed a formatting failure on `main`. Three ways
out, none of them a code change:

- The organisation upgrades to GitHub Team or higher
- The repository becomes public, which makes protection free
- Convention: do not merge on red, and run the gate locally before pushing

Until one of the first two happens, the third is the whole enforcement
mechanism. `docs/how-to/run-the-tests.md` has the commands.

It also does **not** mean the model layer works. Nothing in the suite
creates a row from a factory, so a factory writing a value its column cannot
hold passes all three checks and fails only on insert — this happened with
`fake()->country()` writing a full country name into a `char(2)` column.
`docs/how-to/regenerate-with-blueprint.md` covers the factory check that
catches it.

The first failing step stops the run, so one red step can hide others behind
it. A Pint failure means Larastan and Pest did not execute at all, not that
they passed.

## What it needs no secrets for

No Stripe, Econt, or Speedy credentials are configured in this workflow,
and none should be added for it to pass. External APIs are mocked in
tests — a test that requires a real credential to pass is a test reaching
the network when it should not be.
