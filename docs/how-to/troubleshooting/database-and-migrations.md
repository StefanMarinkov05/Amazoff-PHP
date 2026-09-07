# Troubleshooting — database engine, migrations, and permission cache

Part of the [troubleshooting index](../troubleshooting.md). Errors from
MySQL vs. SQLite divergence, connection/database setup, migration
performance, and the Spatie permission cache.

---

## A Pest dataset fails with `Call to undefined method Container::databasePath()`

**Symptom.** A test using `dataset()` fails before any assertion runs.

**Cause.** Dataset closures are evaluated during test collection, before the
application is booted. Framework helpers like `database_path()` do not exist
yet.

**Fix.** Resolve paths from `__DIR__`:

```php
$paths = glob(dirname(__DIR__, 2).'/database/factories/*Factory.php');
```

**Why it recurs.** The helper works everywhere else in the test file — inside
`it()`, inside `beforeEach()` — so the restriction is invisible until the
closure is a dataset.

**Prevention.** Framework helpers are unavailable in dataset closures. Use
filesystem paths there and helpers inside the test body.

---

## A permission change saves and does not take effect

**Symptom.** A role is edited — through the panel, a seeder, or tinker — the
change is visibly in the database, and `$user->can(...)` keeps returning the
old answer. Reloading the edit form shows the new state, because the form
reads the database directly. In tests, the second test in a run fails on
permissions the first test's `beforeEach` seeded.

**Cause.** `spatie/laravel-permission` caches the entire permission table for
24 hours through `PermissionRegistrar`. Authorization answers from that cache;
the form answers from the database. When they disagree, everything visible
says the change worked.

The package clears the cache itself when permissions change through its own
model methods (`givePermissionTo`, `syncPermissions`, `revokePermissionTo`).
It cannot know about a direct pivot write, a raw query, or `RefreshDatabase`
truncating the tables underneath it.

**Fix.**

```php
app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
```

Already in three places: `PermissionSeeder::run()` before writing, so
`RoleSeeder` does not read a pre-seed cache; `EditRole::afterSave()`, so a
panel edit is live on the next request; and the `beforeEach` of both
permission test files, because `RefreshDatabase` truncates without clearing.

**Why it recurs.** Every symptom points somewhere else. The database is
correct, the form is correct, the seeder reported success — the only wrong
answer comes from the one component nobody is looking at. It is also
invisible locally when the cache happens to be cold and appears in CI when it
is not, or the reverse.

**Prevention.** Any code path that writes to `role_has_permissions`,
`model_has_roles`, or `model_has_permissions` without going through a spatie
model method has to clear the cache afterwards. Prefer the model methods,
which handle it. When adding a test that seeds permissions, copy the
`beforeEach` from `tests/Feature/RolePermissionTest.php` rather than writing a
new one.

---

## Migrations and tests are unbearably slow

**Symptom.** `migrate:fresh` takes six or seven minutes. The test suite takes
the same. A single `CREATE TABLE` followed by `DROP TABLE`, run directly inside
the database container, takes eight seconds.

**Cause.** The MySQL container ran with production durability on a Docker
Desktop volume: `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, and the
binary log enabled. Every statement was fsynced to a Windows-backed filesystem,
and DDL pays that cost repeatedly.

**Fix.** Relaxed in `docker-compose.yml` — flush at 2, `sync_binlog=0`,
`--skip-log-bin`. `migrate:fresh` went from 6m51s to seconds and the suite from
7m34s to 43s.

**Why it recurs.** The defaults are correct for production and nobody changes
them for local work, because the cost is invisible until something runs DDL in
a loop. It looks like Laravel being slow, or the machine being slow.

**Prevention.** This container is development and tests only; production runs on
Forge with MySQL's defaults intact. The settings are commented in
`docker-compose.yml` so nobody restores them thinking they are a safety
improvement. If migrations start crawling again, check these first.

**Update, 2026-08-17 — a separate slow file, not a process-level cost.**
While sizing CI shards, a ~30s gap that first looked like a fixed
per-process bootstrap cost (present regardless of which file ran first)
turned out, on careful re-reading of the CI log, to belong entirely to
`tests/Feature/RolePermissionTest.php`. Its `beforeEach` calls
`forgetCachedPermissions()` and reseeds `PermissionSeeder`, `RoleSeeder`,
and `UserSeeder` before every single test, not once per file — deliberate,
per its own comment (the permission registrar caches for 24h; without
forgetting it, the second test resolves against the first test's
now-truncated rows), but expensive across the file's many
dataset-driven tests. Not a bug, not environment-specific: reproduces
identically in CI and locally once measured correctly. First misattributed
to whichever file happened to sit near it in a given run's PASS-line
ordering — a Pest `PASS` line prints after a file's last test finishes, so
a naive timestamp diff between consecutive `PASS` lines attributes the gap
to the *next* file rather than the one that just finished, which pointed
at `ReportsDomainFailuresTest` and later at a phantom "local Docker Desktop
schema-load cost" before the real cause (this file's `beforeEach`) was
isolated by checking each test's own self-reported duration inside the
file, not the gap before its `PASS` line. Logged here so `RolePermissionTest`'s
cost isn't re-diagnosed as CI flakiness, a schema issue, or attributed to
whatever file happens to run next to it.

---

## A migration with many ALTER TABLE statements takes minutes

**Symptom.** A migration that adds a few dozen constraints runs for several
minutes, while the same work batched runs in seconds.

**Cause.** Each `ALTER TABLE` is a separate round trip and MySQL may rebuild the
table for each one. Forty-five separate statements took 4m57s; the same
constraints grouped into one `ALTER TABLE` per table took 39s.

**Fix.** Group clauses per table:
`ALTER TABLE x ADD CONSTRAINT a ..., ADD CONSTRAINT b ...`.

**Why it recurs.** Writing one statement per constraint is the obvious shape,
reads more clearly, and is what a loop over a list produces naturally.

**Prevention.** In any migration touching more than a handful of columns on 1
table, build the clauses and issue a single `ALTER TABLE`.

---

## `schema:dump` fails with unknown mysqldump variables

**Symptom.** `php artisan schema:dump` fails with
`mysqldump: unknown variable 'column-statistics=0'` and a TLS error.

**Cause.** Debian's `default-mysql-client` package is MariaDB's client, which
does not accept the MySQL-only flags Laravel passes.

**Fix.** Install Oracle's `mysql-client` from MySQL's apt repository. Already
done in `docker/php/Dockerfile`.

**Why it recurs.** The package name says `mysql` and the binary is called
`mysqldump`, so nothing suggests a different vendor until a MySQL-specific flag
is used.

**Prevention.** Two details in that Dockerfile look like mistakes and are not:
the signing key is `RPM-GPG-KEY-mysql-2025` because the 2023 key expired on
2025-10-22, and the apt suite is `bookworm` although the image is Debian trixie,
because MySQL's trixie suite currently ships no `mysql-8.0` component. Check
`mysqldump --version` reports MySQL and not MariaDB before debugging further.

---

## A constraint holds in development and not under test

**Symptom.** A `CHECK` constraint, an `enum` column, or a column length rejects
bad data when used by hand, but a test writing the same value passes.

**Cause.** The suite was running against SQLite in memory. SQLite ignores
`VARCHAR` lengths, stores `enum` columns as free text, and cannot execute
`ALTER TABLE ADD CONSTRAINT` at all — so the migration adding the 45 `CHECK`
constraints skipped itself there and none of them existed during a test run.

**Fix.** `phpunit.xml` points at MySQL and the `amazoff_test` database.
Host, port, and credentials come from the environment; only the database name is
overridden, so a test run cannot touch development data.

**Why it recurs.** SQLite in memory is the Laravel default for tests and is
genuinely faster. The divergence is silent: nothing reports that a constraint
was not applied, and the suite stays green while the guarantee is absent.

**Prevention.** The suite runs on the engine production uses. The cost that
usually pushes people back to SQLite — a slow `migrate:fresh` — is the
durability problem above, not MySQL itself; with that fixed the full suite runs
in about forty seconds.

---

## The app is silently running on SQLite instead of the Docker MySQL container

**Symptom.** `docker compose exec app php artisan tinker --execute="echo
DB::connection()->getDriverName();"` prints `sqlite`, not `mysql`. Nothing
looks broken — `migrate:fresh --seed` runs, the app loads, data persists.
`./vendor/bin/pest` fails with `Access denied for user 'root'@'...' (using
password: NO)`.

**Cause.** `src/.env` is the generic Laravel skeleton (`APP_NAME=Laravel`,
`DB_CONNECTION=sqlite`, no Stripe/Econt/Speedy keys) rather than this project's
own `src/.env.example` (`DB_CONNECTION=mysql`, `DB_HOST=db`,
credentials matching `docker-compose.yml`'s `db` service). This happens when
`.env` was created by an earlier `artisan key:generate` or framework
bootstrap before the setup step that copies the project's `.env.example`, or
was never replaced afterward. `phpunit.xml` forces `DB_CONNECTION=mysql` for
tests regardless of `.env`, so Pest tries MySQL anyway — with no
`DB_PASSWORD` set anywhere, using an empty one.

**Fix.**

```bash
cp src/.env.example src/.env
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan filament:assets
```

**Why it recurs.** SQLite works well enough that nothing forces the mistake
into view — the app runs, the panel logs in, data survives a restart. The
gap is the same one the constraint entry above describes: `VARCHAR` lengths,
`enum` enforcement, and all 45 `CHECK` constraints are silently absent, and
nothing about a working app reveals that.

**Prevention.** After `docker compose up -d --build`, confirm the driver
before trusting anything else:

```bash
docker compose exec app php artisan tinker --execute="echo DB::connection()->getDriverName();"
```

Should print `mysql`.

---

## `pest` fails locally with "Access denied ... to database 'amazoff_test'"

**Symptom.** `./vendor/bin/pest` fails on every test with `SQLSTATE[HY000]
[1044] Access denied for user 'sail'@'%' to database 'amazoff_test'`,
even though the app itself connects to MySQL fine and `amazoff` has data
in it.

**Cause.** `docker-compose.yml`'s `db` service only provisions
`MYSQL_DATABASE: amazoff` — the single database the app uses.
`phpunit.xml` points Pest at a second, separate database,
`amazoff_test`, so development data is never at risk from a test run.
Nothing created that second database locally. CI doesn't hit this because
its MySQL service container is configured with `MYSQL_DATABASE:
amazoff_test` directly (`.github/workflows/ci.yml`) and runs as `root`,
which has access to everything by default.

**Fix.** `docker/mysql/init/01-test-database.sh`, mounted into the `db`
service at `/docker-entrypoint-initdb.d/`, creates `amazoff_test` and
grants the app user (`sail`) access to it. It runs automatically the first
time the container initializes an empty `db_data` volume — a fresh clone, or
`docker compose down -v` followed by `up`. It does not run retroactively
against a volume that already has data; run it by hand once for an existing
volume:

```bash
docker compose exec db mysql -uroot -p"$DB_PASSWORD" -e "
    CREATE DATABASE IF NOT EXISTS amazoff_test;
    GRANT ALL PRIVILEGES ON amazoff_test.* TO 'sail'@'%';
    FLUSH PRIVILEGES;
"
```

**Why it recurs.** `docker-entrypoint-initdb.d` scripts are silent on a
volume that already exists — there's no error, no log line pointing at the
missing database, just a Pest failure that reads like a credentials problem
rather than a missing-database one.

**Prevention.** The init script is now committed, so this only affects a
volume that existed before it was added. Anyone hitting this on an older
volume runs the manual `GRANT` above once; anyone starting fresh (or wiping
`db_data`) gets it automatically.
