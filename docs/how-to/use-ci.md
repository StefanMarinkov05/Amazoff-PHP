# How to use CI

`.github/workflows/ci.yml`. Nothing to run — it triggers on its own.

## When it runs

Any push to `main`, and any pull request targeting `main`. Pushing to a
feature branch on its own does not trigger it; opening a PR from that
branch does.

## Where to see it

On a PR, in the checks section near the bottom, listed as "CI / test". The
repo's **Actions** tab has the full run history.

## What it does, in order

1. Checks out the code.
2. Installs PHP 8.4 with the extensions the app needs
   (`mbstring, pdo_mysql, bcmath, gd, zip, intl, exif`).
3. Installs Node 22.
4. `composer install`, `npm ci`, `npm run build`.
5. Copies `.env.example` to `.env`, generates an app key.
6. Starts a real MySQL 8 service container and runs
   `php artisan migrate --force` against it.
7. Runs `pint --test`.
8. Runs `phpstan analyse` (Larastan).
9. Runs `pest`.

Any step failing turns the whole check red and stops the run — later steps
do not execute.

## Why Pest runs against SQLite, not the MySQL service

`online-store/phpunit.xml` pins `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:` for the test run, regardless of what MySQL service
is available in the job. This is intentional, not a leftover: tests run
fast and isolated against an in-memory database. The MySQL service exists
for step 6 — proving the migrations actually apply to a real MySQL schema,
which SQLite would not catch every incompatibility for.

## Reproducing a CI failure locally

Same three commands the workflow runs, through Docker:

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest
```

`pint --test` only checks formatting and reports violations — it does not
fix them. Run `./vendor/bin/pint` (no `--test`) to actually apply the fixes,
then re-run `--test` to confirm.

## What a green check does and does not mean

Green means Pint, Larastan, and Pest all passed.

It does **not** block a merge by itself. That requires a branch protection
rule on `main` (GitHub → Settings → Branches → require this status check),
a separate admin-level setting not controlled by this file. Until that rule
exists, a red check is visible but not enforced.

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
