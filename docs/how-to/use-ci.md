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

On a PR, in the checks section near the bottom, listed as "CI / test" and
"CI / test-concurrency" — two jobs, running in parallel. The repo's
**Actions** tab has the full run history.

## What it does, in order

Two jobs, each with its own MySQL 8 service container (services are not
shared across jobs), running in parallel rather than one after another.

**`test`** — everything except `tests/Concurrency`:

1. Checks out the code.
2. Installs PHP 8.4 with the extensions the app needs
   (`mbstring, pdo_mysql, bcmath, gd, zip, intl, exif`), PCOV enabled.
3. Installs Node 22.
4. `composer install`, `npm ci`, `npm run build`.
5. Copies `.env.example` to `.env`, generates an app key.
6. Runs `php artisan migrate --force`.
7. Runs `pint --test`.
8. Runs `phpstan analyse` (Larastan).
9. Runs `pest tests/Unit tests/Feature --coverage --coverage-clover=coverage.xml`,
   uploads `coverage.xml` as an artifact.

**`test-concurrency`** — only `tests/Concurrency`:

1. Checks out the code.
2. Installs PHP 8.4 with the same extensions, no coverage driver.
3. `composer install` (no npm/build step — these tests call Actions
   directly, no HTTP or Livewire rendering involved).
4. Copies `.env.example` to `.env`, generates an app key.
5. Runs `php artisan migrate --force`.
6. Runs `pest tests/Concurrency`.

Within a job, any step failing turns that job red and stops it — later
steps in that job do not execute. The two jobs don't block each other.

### Why concurrency tests run separately

`tests/Concurrency/*` spawns real `php` subprocess pairs synchronized on a
wall-clock barrier (`explanation/concurrency-and-locking.md`) — each test
pays several seconds of process-boot and barrier-wait overhead that a
Feature test doesn't. Measured on a full run: 9 concurrency test files took
about as long as the other ~30 test files combined (roughly 103s of a
181s total). Splitting them into a parallel job doesn't reduce that time,
but it stops it from sitting on the same critical path as everything else,
cutting wall-clock time on the PR check without cutting test count.

It also has no coverage driver, matching ADR-0009's documented blind spot:
PCOV cannot see into a separate `php` subprocess, so collecting coverage in
this job would cost time and prove nothing.

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
See the entry in `troubleshooting.md`; the full suite runs in about forty
seconds.

## Reproducing a CI failure locally

Same commands the workflow runs, through Docker:

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest
```

`pest` with no path runs everything, `test` and `test-concurrency` combined.
To reproduce one job exactly: `pest tests/Unit tests/Feature` or
`pest tests/Concurrency`.

`pint --test` only checks formatting and reports violations — it does not
fix them. Run `./vendor/bin/pint` (no `--test`) to actually apply the fixes,
then re-run `--test` to confirm.

## What a green check does and does not mean

Green means Pint, Larastan, and Pest (both jobs) all passed.

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
