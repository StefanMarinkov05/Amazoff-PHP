# Troubleshooting

Errors that have already cost someone an afternoon, and what stops each one
coming back.

Every entry carries five parts: the **symptom** as it actually appears, the
**cause**, the **fix**, **why it recurs** — because an error that only happened
once does not need a document — and **prevention**, the change that stops it
happening again rather than the one that got past it this time.

Entries are added by whoever solves the problem, in the same PR as the fix. An
entry whose prevention has since been automated stays, with the guard named, so
nobody removes the guard without knowing what it was for.

---

## Pint, Larastan, and Pest all pass, then the insert fails

**Symptom.** `SQLSTATE[22001]: String data, right truncated` or a similar
constraint error, on `migrate:fresh --seed` or in a test that creates a row.
Every static check was green.

**Cause.** A factory writes a value the column cannot hold. Blueprint maps
column names to Faker methods without consulting column types, so `country`
becomes `fake()->country()` — a full country name — in a `char(2)` column.

**Fix.** `fake()->countryCode()`. See `AddressFactory` and `OrderAddressFactory`.

**Why it recurs.** Static analysis reads types, not values. `php -l` proves
syntax, Larastan proves types, Pest proves the paths it covers — none of them
executes an INSERT. The bug is invisible until the database rejects it, and
every Blueprint regeneration can reintroduce it.

**Prevention.** `tests/Feature/FactoryTest.php` persists one row from every
factory, discovered by glob, so a new factory is covered the moment it exists.
Do not delete it because it looks trivial; it is the only check in the suite
that touches this class of bug.

**Bound the generator, do not truncate the result.** `fake()->slug()` defaults
to about six words and reaches 85 characters, so against a `varchar(60)` it
fails perhaps one run in three — green locally, red in CI, and green again on
re-run. Pass a word count instead: `slug(2)` tops out around 40 characters,
`slug(3)` around 54, `slug(4)` around 60. A `substr()` wrapper also works but
produces slugs cut mid-word, which then read as real data in the demo seed.

**A fix for one factory is a fix for one factory.** When this appeared in
`AttributeFactory` it was corrected there and nowhere else; `TagFactory` had
the identical bug against the identical `varchar(60)` and failed in CI two
merges later. On any truncation failure, grep the whole factory directory for
the same generator before calling it fixed:

```bash
grep -rn "fake()->slug()" online-store/database/factories/
```

That test only works because the suite runs on MySQL. It previously ran on
SQLite in memory, which ignores `VARCHAR` lengths entirely — `AttributeFactory`
wrote a slug past its `varchar(60)` and the test passed every time. See the
entry below on what SQLite does not enforce.

## Regenerating with Blueprint leaves factories referencing dead columns

**Symptom.** After editing `draft.yaml` and re-running `blueprint:build`,
factories set columns that no longer exist, or `use App\Models\;` with an empty
class name.

**Cause.** Blueprint never overwrites an existing file. A second run adds new
columns to files that still carry the old ones, and skips anything already
present.

**Fix.** Delete the generated files first, then regenerate — the full procedure
is in `regenerate-with-blueprint.md`.

**Why it recurs.** "Regenerate" reads as "replace". It does not, and the failure
is silent: the command reports success and the stale definitions survive.

**Prevention.** Follow the how-to rather than running `blueprint:build` from
memory, and keep `--only=models,factories` in the command so migrations are
never regenerated. Verify with `migrate:fresh` plus the factory test, not by
reading the diff.

---

## A bare `blueprint:build` breaks `migrate:fresh`

**Symptom.** `migrate:fresh` fails on a duplicate table after a regeneration.

**Cause.** Without `--only=models,factories`, Blueprint emits a second
`create_*_table` migration for every table, with a fresh timestamp.

**Why it recurs.** The flag is easy to drop, and the extra migrations look
plausible in a file listing.

**Prevention.** After any regeneration, confirm that only the intended new
migration appeared before running anything else.

---

## A factory recurses until it runs out of memory

**Symptom.** `ProductCategory::factory()->create()` hangs or exhausts memory.

**Cause.** Blueprint generates a factory reference for every foreign key
regardless of nullability. On the self-referencing `parent_id` that means every
category creates a parent, which creates a parent.

**Fix.** `'parent_id' => null`, with a `childOf()` state for building nested
categories.

**Why it recurs.** Any new self-referencing key gets the same treatment on the
next regeneration.

**Prevention.** The factory test catches it as a hang rather than a pass. When
adding a self-referencing key to `draft.yaml`, correct the generated factory in
the same pass.

---

## The IDE reports core Laravel and Filament types as undefined

**Symptom.** `Undefined type 'Illuminate\Database\Eloquent\Model'`,
`Undefined method 'hasMany'`, or `Undefined type
'Filament\Support\Contracts\HasLabel'` on code that runs correctly.

**Cause.** `vendor/` is installed inside the container, not on the host, so the
IDE has nothing to index. Filament compounds it — its heavy trait use produces
false "undefined method" reports even when vendor *is* indexed.

**Fix.** None needed. Confirm with the real toolchain:

```bash
docker compose exec app ./vendor/bin/phpstan analyse
```

**Why it recurs.** The diagnostics look exactly like real errors and appear on
every file touched.

**Prevention.** Larastan in the container is the authority for type errors. A
red squiggle with a green Larastan run is noise; treat the container's answer as
the only one.

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

## A class fails to autoload after an editor revert

**Symptom.** `Error: Interface "App\Enums\HasLabel" not found`, on a file that
was correct minutes earlier and shows no intentional change.

**Cause.** An editor undo or a stale buffer flush restored an earlier version of
the file. Here it reverted a `use` statement to a form with the namespace
separators stripped, so `implements HasLabel` resolved against the current
namespace instead of the imported one.

**Fix.** Restore the correct import. Check open buffers for other stale copies
before continuing.

**Why it recurs.** The file looks nearly right, git shows a small diff, and the
error names a class nobody wrote.

**Prevention.** An error naming a type in the *current* namespace that should
have been imported is an import problem, not a missing class. Confirm with a
full-directory grep rather than checking the file the error points at:

```bash
grep -rn "^use " online-store/app/Enums/
```

---

## Shell find-and-replace mangles PHP namespaces

**Symptom.** `use FilamentSupportContractsHasLabel;` or `use App\Enums${e};`
after a `sed` or `perl` sweep. Backslashes disappear or variables fail to
expand.

**Cause.** Namespace separators are escape characters in every layer they pass
through — the shell, the regex engine, and the replacement string. `\E` in a
Perl pattern terminates a quoted section, so `App\Enums` silently stops matching
at `App`.

**Fix.** Repair the files, then use the editor tooling for anything containing a
namespace. Reserve shell replacement for text with no backslashes.

**Why it recurs.** It appears to work — the command reports success and most
lines look right, so the damage is found later by the autoloader.

**Prevention.** Never pipe PHP namespaces through `sed` or `perl`. If a sweep is
unavoidable, verify immediately:

```bash
grep -rh "^use " online-store/app/Enums/ | sort -u
```

---

## `migrate:fresh --seed` produces no staff and no demo data

**Symptom.** Nobody can log into `/admin` after a fresh clone. The panel rejects
every account.

**Cause.** The three staff roles exist in one developer's local database but
have no seeder, so a fresh database has none, and `canAccessPanel()` gates the
panel on holding one.

**Fix.** `database/seeders/RoleSeeder.php` creates the three
`User::STAFF_ROLES` rows and runs in every environment, called from
`DatabaseSeeder`. `DatabaseSeeder` additionally creates a staff account and
assigns it `administrator`, but only outside production
(`! app()->isProduction()`) — see the next entry for why that guard matters.

**Why it recurs.** It is invisible to anyone whose database predates the
problem, which is everyone who has been working on the project.

**Prevention.** Reference data the application needs in order to work at all
belongs in `DatabaseSeeder`, not in a developer's database. Test setup changes
against a genuinely fresh database rather than an existing one.

---

## A seeded admin account becomes a default production credential

**Symptom.** Not yet observed here — a risk caught during review of the
`RoleSeeder`/`DatabaseSeeder` change above, not a bug that happened.

**Cause.** ADR-0003 states that `DatabaseSeeder` runs in production, for
reference data the application needs to function — roles, carriers, VAT
rates. It does not list staff accounts as reference data. A seeder that
unconditionally creates `admin@example.com` with a factory-default password
would create that exact account, with that exact password, on every
production deploy that runs the seeder.

**Fix.** The admin-account block in `DatabaseSeeder` is wrapped in
`! app()->isProduction()`. The `RoleSeeder` call above it is not — role rows
are safe everywhere, a known password is not.

**Why it recurs.** "Seed a working login for local dev" and "seed reference
data" read as the same instruction, and only one of them is safe to run
unconditionally.

**Prevention.** Before adding anything to `DatabaseSeeder`, check whether it
is reference data (needed everywhere, ADR-0003) or a development convenience
(needed only outside production). When in doubt, gate it.

---

## A Filament resource generated with `--generate` accepts duplicate slugs

**Symptom.** Saving a second record with a slug that already exists throws
an uncaught `QueryException` / 500 page instead of a validation message,
despite the column having a database-level unique constraint.

**Cause.** `make:filament-resource --generate` infers a field's presence,
type, and nullability from the schema, but not its indexes. A `unique()`
column in the migration does not become a `->unique()` rule on the
generated form field.

**Fix.** Add `->unique(ignoreRecord: true)` by hand to match a single-column
unique index. For a composite index — `attribute_values`'
`UNIQUE(attribute_id, slug)` is the one instance in this schema — a plain
`->unique()` on `slug` alone is not just missing, it is *wrong*: it rejects
combinations the database would accept. Scope it with `modifyRuleUsing`:

```php
TextInput::make('slug')
    ->required()
    ->unique(
        ignoreRecord: true,
        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('attribute_id', $get('attribute_id')),
    ),
```

**Why it recurs.** The generated form passes Pint and Larastan and looks
complete — the gap is only visible against the migration, which nothing
forces a reviewer to open.

**Prevention.** After generating any resource, diff its unique/composite
indexes (`docs/reference/schema.md`, "Constraints that carry a rule") against
the form's validation rules before treating the resource as done.

---

## A Filament v4 form field fails only when it's actually used

**Symptom.** `Class "Filament\Forms\Get" not found`, or the equivalent for
`Set` — but only inside a closure (`modifyRuleUsing`, `->live()`, and
similar), and only when that closure actually runs. Pint passes. The IDE's
red squiggle is indistinguishable from the vendor-not-on-host noise below,
so it's easy to dismiss as the same false alarm.

**Cause.** Filament v3 code (and most AI training data, most tutorials)
imports `Filament\Forms\Get` and `Filament\Forms\Set`. Filament v4 moved both
under the shared Schemas namespace:
`Filament\Schemas\Components\Utilities\Get` /
`...\Utilities\Set`. The old class no longer exists. PHP does not resolve a
parameter type hint until the function carrying it is actually called, so a
wrong import here compiles, passes `php -l`, and only throws when the
closure executes — typically the first time a user submits the form.

**Fix.** Import from `Filament\Schemas\Components\Utilities\Get` (or
`Set`). Confirm the real location if in doubt:

```bash
docker compose exec app sh -c "find vendor/filament -iname 'Get.php'"
```

**Why it recurs.** The old namespace still compiles and still looks correct
to Pint, the IDE, and a casual read — nothing about it announces that it's
stale.

**Prevention.** Larastan catches this (`class.notFound`) even though Pint
and the IDE do not. Run `docker compose exec app ./vendor/bin/phpstan
analyse` on any new Filament schema/form code before treating it as done,
not just the mechanical formatters.

---

## The admin panel logs in, then crashes rendering the topbar

**Symptom.** `TypeError: Filament\FilamentManager::getUserName(): Return
value must be of type string, null returned`, on the first authenticated
page after a successful login. The query log shows the user and their role
were already fetched correctly.

**Cause.** `FilamentManager::getUserName()` calls `$user->getFilamentName()`
if the user model implements `Filament\Models\Contracts\HasName`, otherwise
falls back to `$user->getAttributeValue('name')`. A `User` model that
implements `FilamentUser` (for panel access) but not `HasName` hits the
fallback — and this schema has no `name` column, only `first_name` /
`last_name`, so the fallback returns `null` into a `: string` return type.

**Fix.** Implement `HasName` alongside `FilamentUser`:

```php
public function getFilamentName(): string
{
    return "{$this->first_name} {$this->last_name}";
}
```

**Why it recurs.** `FilamentUser` is the interface every setup guide
mentions, because it's required for login to work at all. `HasName` is only
required to render the panel afterwards, so it's easy to add the first and
stop, and the schema deciding on `first_name`/`last_name` instead of `name`
is what turns the omission into a crash rather than a blank label.

**Prevention.** Any model used as a Filament panel guard on a schema without
a `name` column needs `HasName` implemented from the start, not discovered
by hitting the crash.

---

## The admin panel loads but renders with no styling

**Symptom.** `/admin` returns `200` and the page structure is there, but
none of Filament's CSS applies — no theme colour, no layout, plain HTML.
Network tab shows `404` for `/css/filament/filament/app.css` and similar
paths, with a `text/html` content type (Laravel's 404 page, not a missing
static file from nginx).

**Cause.** `public/css/filament`, `public/js/filament`, and
`public/fonts/filament` existed as directories but were empty — the
compiled assets were never copied in. Composer's `post-install-cmd` runs
`php artisan filament:upgrade`, which is supposed to publish them
automatically, but if it runs before the panel and its dependencies are
fully in place, the directories get created with nothing inside.

**Fix.**

```bash
docker compose exec app php artisan filament:assets
```

**Why it recurs.** nginx is configured correctly and nothing in the request
path looks broken — nginx's `root` correctly points at `public/`, so this
reads like a routing or config problem rather than "the files were never
written," which is easy to rule out last instead of first.

**Prevention.** `filament:assets` now runs as an explicit step in
`README.md`'s setup instructions rather than relying on the Composer hook
alone.

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

**Prevention.** In any migration touching more than a handful of columns on one
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

**Fix.** `phpunit.xml` points at MySQL and the `online_shop_test` database.
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

**Cause.** `online-store/.env` is the generic Laravel skeleton (`APP_NAME=Laravel`,
`DB_CONNECTION=sqlite`, no Stripe/Econt/Speedy keys) rather than this project's
own `online-store/.env.example` (`DB_CONNECTION=mysql`, `DB_HOST=db`,
credentials matching `docker-compose.yml`'s `db` service). This happens when
`.env` was created by an earlier `artisan key:generate` or framework
bootstrap before the setup step that copies the project's `.env.example`, or
was never replaced afterward. `phpunit.xml` forces `DB_CONNECTION=mysql` for
tests regardless of `.env`, so Pest tries MySQL anyway — with no
`DB_PASSWORD` set anywhere, using an empty one.

**Fix.**

```bash
cp online-store/.env.example online-store/.env
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

## `pest` fails locally with "Access denied ... to database 'online_shop_test'"

**Symptom.** `./vendor/bin/pest` fails on every test with `SQLSTATE[HY000]
[1044] Access denied for user 'sail'@'%' to database 'online_shop_test'`,
even though the app itself connects to MySQL fine and `online_shop` has data
in it.

**Cause.** `docker-compose.yml`'s `db` service only provisions
`MYSQL_DATABASE: online_shop` — the single database the app uses.
`phpunit.xml` points Pest at a second, separate database,
`online_shop_test`, so development data is never at risk from a test run.
Nothing created that second database locally. CI doesn't hit this because
its MySQL service container is configured with `MYSQL_DATABASE:
online_shop_test` directly (`.github/workflows/ci.yml`) and runs as `root`,
which has access to everything by default.

**Fix.** `docker/mysql/init/01-test-database.sh`, mounted into the `db`
service at `/docker-entrypoint-initdb.d/`, creates `online_shop_test` and
grants the app user (`sail`) access to it. It runs automatically the first
time the container initializes an empty `db_data` volume — a fresh clone, or
`docker compose down -v` followed by `up`. It does not run retroactively
against a volume that already has data; run it by hand once for an existing
volume:

```bash
docker compose exec db mysql -uroot -p"$DB_PASSWORD" -e "
    CREATE DATABASE IF NOT EXISTS online_shop_test;
    GRANT ALL PRIVILEGES ON online_shop_test.* TO 'sail'@'%';
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

---

## A seeded column silently does nothing

**Symptom.** A seeder sets a field and the resulting row does not have it. No
error.

**Cause.** Eloquent discards attributes that are not mass-assignable, or that do
not exist, without complaint. `DatabaseSeeder` passed `name` to a users table
whose columns are `first_name` and `last_name` — fixed when the seeder was
rewritten to add the staff account (see the changelog), but the general
failure mode remains unguarded against for the next seeder that hits it.

**Why it recurs.** The seeder succeeds. Nothing distinguishes a discarded
attribute from an accepted one.

**Prevention.** `Model::preventSilentlyDiscardingAttributes()` in
`AppServiceProvider::boot()`, guarded to non-production, turns this into an
exception. Not yet enabled.

---

## A failing Pint check reaches `main` anyway

**Symptom.** `./vendor/bin/pint --test` fails in CI on `main` itself —
`ordered_imports` on a file nobody touched recently, e.g. one `use`
statement in `ProductCategoriesTable.php` out of alphabetical order. The PR
that introduced it shows the `test` check as failed, not pending, and is
already merged.

**Cause.** Two separate gaps, not one. First, `.github/workflows/ci.yml`
triggers `pull_request` only for PRs targeting `branches: [main]`. A chain
of feature branches merging into each other before finally reaching
`main` (`lookup-resources` → `feature` → `development` → `main`) only runs
CI on that last hop — `gh pr checks` on the earlier PRs in the chain
reports "no checks reported," which reads as clean but means untested, not
passing. Second, `main` has no branch protection rule
(`gh api repos/.../branches/main/protection` returns `404`), so nothing
stops a merge when the one check that did run comes back red.

**Fix.** Run `./vendor/bin/pint` (not `--test`) on the offending file,
confirm the full suite with `--test`, and land the fix as its own small PR
rather than amending history on `main`.

**Why it recurs.** Every intermediate branch in a merge chain looks safe
because nothing failed on it — nothing ran. And a red final check does not
by itself stop the merge button; it only stops it if branch protection is
configured to require that check.

**Prevention.** Two independent fixes, both still open:

1. Run `./vendor/bin/pint` locally before pushing to *any* branch, not just
   before the PR into `main` — don't rely on CI in a chain to catch it
   first, since most links in the chain don't run CI at all.
2. Enable a branch protection rule on `main` requiring the `test` check to
   pass before merging. Until that exists, a red run is advisory, not a
   gate, no matter what the workflow file says.
