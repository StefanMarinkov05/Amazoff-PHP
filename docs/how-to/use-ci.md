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
four "CI / test (1/4)"–"(4/4)" shards, three "CI / test-concurrency
(1/3)"–"(3/3)" shards, and "CI / test-browser" — nine jobs, running in
parallel. The repo's **Actions** tab has the full run history. Shard
counts are `strategy.matrix.shard` in `ci.yml`, changed in one place if the
number of shards itself is ever revisited — see "Adding a new test file"
below for what does and does not need touching day to day.

## What it does, in order

Nine jobs. `test`, `test-concurrency`, and `test-browser` each have their
own MySQL 8 service container (per shard where sharded; services are not
shared across jobs or matrix shards); `lint` needs no database. All run in
parallel rather than one after another.

**`lint`** — no database needed:

1. Checks out the code.
2. Installs PHP 8.4, no coverage driver.
3. `composer install`.
4. Runs `pint --test`.
5. Runs `phpstan analyse` (Larastan).

**`test`** — a 4-shard, time-balanced matrix over `tests/Unit` and
`tests/Feature`:

1. Checks out the code.
2. Installs PHP 8.4 with the extensions the app needs
   (`mbstring, pdo_mysql, bcmath, gd, zip, intl, exif`), no coverage driver.
3. `composer install` (no npm/build step — nothing under `tests/Unit` or
   `tests/Feature` touches a compiled asset or calls `@vite`/`Vite::` /
   `mix()`, verified by grep before removing the step).
4. Copies `.env.example` to `.env`, generates an app key.
5. Runs `php artisan migrate --force`.
6. Runs `pest --testsuite=Feature,Unit --shard=<N>/4` — the committed
   `tests/.pest/shards.json` decides which classes land in shard `N`
   (ADR-0018; "Adding a new test file" below).

**`test-concurrency`** — a 3-shard, time-balanced matrix over
`tests/Concurrency`:

1. Checks out the code.
2. Installs PHP 8.4 with the same extensions, no coverage driver.
3. `composer install` (no npm/build step — these tests call Actions
   directly, no HTTP or Livewire rendering involved).
4. Copies `.env.example` to `.env`, generates an app key.
5. Runs `php artisan migrate --force`.
6. Runs `pest --testsuite=Concurrency --shard=<N>/3`, from the same
   committed `shards.json`.

**`test-browser`** — the Pest 5 real-browser suite (`tests/Browser/`,
ADR-0017), one job, not sharded (~7 tests, ~2 min, dominated by per-test
Chromium context setup that sharding would not help). Its MySQL service
provisions `amazoff_browser`, not `amazoff_test`.

1. Checks out the code.
2. Installs PHP 8.4 with the same extensions **plus `sockets`** —
   `pestphp/pest-plugin-browser` talks to its Playwright server over a
   socket.
3. Installs Node 22, then `npm ci`, then
   `npx playwright install --with-deps chromium` (the browser binaries and
   their system libraries), then **`npm run build`**. The build is not
   optional: `pest-plugin-browser` boots the app in-process and `@vite`
   must resolve against the built manifest — an unstyled page makes
   `ResponsiveTest`'s compiled-CSS precondition fail, which is the point of
   that precondition.
4. `composer install`, `.env`, key, `php artisan migrate --force` against
   `amazoff_browser`.
5. Runs `pest -c phpunit.browser.xml`. No app server is started —
   `pest-plugin-browser` runs the HTTP kernel in-process.

`ThreeDSecureTest` (once it exists) skips itself here: it needs a real
Stripe test key and a live `stripe listen`, and no Stripe/Econt/Speedy
secret goes in CI (see "Secrets" below). It is a local runbook.

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

Within each suite, time is not evenly spread — ADR-0010 has the original
measured numbers and the false leads ruled out finding them (a PASS line's
timestamp belongs to the file that just finished, not the one about to
start; a phantom "Docker Desktop schema-load cost" that was actually one
file's expensive `beforeEach`).

### Adding a new test file: nothing to decide

**Time-balanced sharding (ADR-0018), not hand-partitioning.** Both `test`
and `test-concurrency` run `pest --shard=<N>/<total>`, which reads the
committed `tests/.pest/shards.json` — real per-class wall-clock time from
the last `--update-shards` run — and bin-packs the heaviest classes into
the lightest shard first (`Pest\Plugins\Shard::partitionByTime()`; greedy,
by descending duration). A new test file has no timing entry yet, so it
round-robins across shards by list order until the next refresh — never
concentrated in one shard, and Pest itself prints a loud
`WARN  The [tests/.pest/shards.json] file is out of date. Run
[--update-shards] to update it.` in the job log the moment a run sees a
class the file doesn't know about, which is the re-balance trigger: no
"does this file look slow" judgment call, no fixed shard-count target,
just refresh when the warning appears (or on a normal cadence — see
below).

**Refreshing the timings:**

```bash
docker compose exec app ./vendor/bin/pest --testsuite=Feature,Unit,Concurrency --update-shards
git -C .. add src/tests/.pest/shards.json
```

One invocation across all three testsuites, not one per suite —
`--update-shards` rewrites the file scoped to whatever `--testsuite`/path
filter it was given, so a second run scoped to a different suite would
silently drop the first run's classes rather than merge with them. Commit
the refreshed file in the same PR as whatever test changes prompted it (a
new file, a materially slower one, several deletions) — it costs one
sequential run of the whole suite (~feature/unit plus concurrency
together, since `tests/Concurrency` must never run under `--parallel` —
CLAUDE.md), so it is a deliberate refresh, not a per-commit CI step.

This replaced a hand-partitioned matrix (4 shards for `test`, 3 for
`test-concurrency`, each file's path listed by name in `ci.yml`) that had
already drifted out of balance again by the time it was measured against
Pest 5's own mechanism — see ADR-0018's "Verified" addendum for the real
before/after numbers. The old scheme needed a person to notice a shard
"visibly outrunning the others" and re-derive the split by hand each time;
this one self-reports drift and rebuilds the split from a single command.

No job in this workflow collects coverage. It was dropped from CI
entirely rather than merged across shards or kept on one shard only — see
ADR-0009 and ADR-0010 for why. Generate it locally with `pest --coverage`
(`docs/reference/testing/coverage.md` has the commands) when the numbers are
actually needed.

## Why Pest runs against the MySQL service

`src/phpunit.xml` sets `DB_CONNECTION=mysql` and
`DB_DATABASE=amazoff_test` (`phpunit.browser.xml` sets `amazoff_browser` for
the browser suite). Host, port, and credentials come from the environment,
so the same file works in CI and in Docker locally, and only the database
name is overridden — a test run cannot touch development data.

It used to run against SQLite in memory, which was faster and wrong. SQLite
ignores `VARCHAR` lengths, keeps `enum` columns as free text, and has no
`ALTER TABLE ADD CONSTRAINT`, so the migration adding the 45 `CHECK`
constraints (ADR-0005) skipped itself and none of them existed during a test
run. A factory writing past a `varchar(60)` passed every time.

The cost that normally argues for SQLite — a slow `migrate:fresh` — turned out
to be the database container's durability settings rather than MySQL itself.
See the entry in `how-to/troubleshooting/database-and-migrations.md`.

## Reproducing a CI failure locally

Same commands the workflow runs, through Docker:

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest
```

`pest` with no path runs everything, every shard of `test` and
`test-concurrency` combined. To reproduce one shard exactly, pass the same
`--shard` argument the job uses (`matrix.shard` and the total are both in
`ci.yml`):

```bash
docker compose exec app ./vendor/bin/pest --testsuite=Feature,Unit --shard=2/4
docker compose exec app ./vendor/bin/pest --testsuite=Concurrency --shard=1/3
```

`pest tests/Unit tests/Feature` alone reproduces all four `test` shards
together, and `pest tests/Concurrency` alone reproduces all three
`test-concurrency` shards together — no `--shard` needed for that, since
skipping it just runs everything in the path given.

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
