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

**Fix.** `database/seeders/System/RoleSeeder.php` creates the three
`User::STAFF_ROLES` rows and runs in every environment, called from
`DatabaseSeeder` after `PermissionSeeder`. `UserSeeder` then creates one
account per role plus a plain customer, but only outside production
(`! app()->isProduction()`) — see the next entry for why that guard matters.

Credentials are in `reference/permissions.md`; all four use the password
`password`.

**Why it recurs.** It is invisible to anyone whose database predates the
problem, which is everyone who has been working on the project.

**Prevention.** Reference data the application needs in order to work at all
belongs in `DatabaseSeeder`, not in a developer's database. Test setup changes
against a genuinely fresh database rather than an existing one.

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

## An authorization test passes with the authorization check deleted

**Symptom.** A test asserting that a denied actor cannot perform an Action is
green. Deleting the `Gate::forUser($actor)->authorize(...)` call from that
Action leaves it green.

**Cause.** The Action composes another Action, and the *inner* one raised the
`AuthorizationException` the test attributed to the outer one. In this
codebase `CreateProduct` calls `AddProductVariation`, and ADR-0007 requires
the actor to be passed down, so both check a policy. A test actor holding
neither `create_product` nor `create_product_variation` is denied twice, and
the assertion cannot tell which check did it.

**Fix.** Give the actor every permission the composed Actions need *except*
the one under test:

```php
// Tests CreateProduct's own gate, because AddProductVariation's will pass.
$actor = catalogueActor('create_product_variation');
```

**Why it recurs.** `expect(...)->toThrow(AuthorizationException::class)` is the
natural assertion and it is correct — the exception type is right, the write
really was prevented, and the test description matches what happened. Only the
attribution is wrong, and nothing in the output distinguishes the two sources.
Every composed Action that passes an actor down can reintroduce it, and
ADR-0007 requires them all to.

**Prevention.** Delete the check under test and confirm the test goes red.
Grant the actor exactly 1 permission short of success rather than granting
none — an actor with no permissions at all is denied by whichever check runs
first, which is rarely the one being tested.

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
indexes (`docs/reference/schema/schema.md`, "Constraints that carry a rule") against
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

## A removed Filament action is still on the screen

**Symptom.** A create or delete button an administrator should not have is
still rendered after the page class and its `getPages()` entry were removed.
Pressing it opens a modal rather than 404ing, and saving fails on insert:

```
SQLSTATE[HY000]: General error: 1364 Field 'name' doesn't have a default value
```

**Cause.** Two separate things, both required.

A capability lives in three independent places, and removing one leaves the
others: the **route** (`getPages()` plus the page class), the **header
button** (`getHeaderActions()` on the `ListRecords` page), and the **row
button** (`recordActions()` in the table). With the route gone but the action
still registered, Filament falls back to rendering it as a modal.

The policy does not save you. `ContactMessagePolicy::create()` returns a hard
`false`, but `Gate::before` in `AppServiceProvider` grants an administrator
every ability before any policy is consulted — verified: the same call
returns `false` for `content_editor` and `true` for `administrator`.

**Fix.** Remove the action, do not rely on the policy:

```php
protected function getHeaderActions(): array
{
    return [];
}
```

**Why it recurs.** Every resource in `PermissionCatalogue::NON_AUTHORED_RESOURCES`
— `payment`, `product_review`, `contact_message`, `newsletter_subscriber` —
has this shape, and `--generate` scaffolds a `CreateAction` into all of them.
The button also looks correctly gated to anyone testing as an administrator,
because it *is* correctly gated for everybody else.

**Prevention.** ADR-0006 states the tradeoff: a policy cannot deny an
administrator anything, so "nobody may do this" has to be structural. After
removing a capability, grep rather than trusting the diff:

```bash
grep -rn "CreateAction\|DeleteAction" online-store/app/Filament/Resources/<Resource>/
```

---

## A reactive Filament field never reacts, and a CHECK constraint 500s

**Symptom.** A field whose `visible()`, `maxValue()`, `prefix()`, or
`suffix()` depends on another field's value behaves as though the condition
is always false: an adornment never appears, a conditional field is never
shown, a conditional bound never applies. The bound one is the dangerous
half — validation passes and the database rejects the row:

```
SQLSTATE[HY000]: General error: 3819 Check constraint
'chk_coupons_percentage_within_bounds' is violated.
```

**Cause.** The field being read is a `Select` with `->options(SomeEnum::class)`.
That registers an enum on the component, which installs `EnumStateCast`, and
`Get::__invoke()` returns `$component->getState()` — cast state. So
`$get('type')` is a `CouponType` instance, never the string `'percentage'`.
Comparing it against `CouponType::Percentage->value` compares an object to a
string: always false, silently.

The failure is invisible in the obvious places. A conditional field that
never renders looks like a field that was never added, so a form can look
correct while several of its fields are unreachable.

**Fix.** `Get::enum()`, which accepts either representation:

```php
->visible(fn (Get $get) => $get->enum('scope', CouponScope::class, isNullable: true) === CouponScope::Products)
```

`isNullable: true` matters on a create page, where the field starts empty and
the helper would otherwise pass null into `tryFrom()`.

**Why it recurs.** Both sides of the comparison are legal types, so no tool
objects. Pint passes, Larastan passes, and they pass identically on the
broken and the fixed version — the difference is a runtime value, not a type.
Every enum-backed `Select` that anything reacts to can reintroduce it.

**Prevention.** Treat any closure reading `$get()` on an enum-backed `Select`
as needing `Get::enum()` rather than a raw comparison. Verify in the running
app: change the driving field and watch the dependent one, then submit a
value the CHECK constraint forbids and confirm a field error rather than a
500. A Livewire test asserting `assertHasFormErrors()` and
`assertFormFieldVisible()` pins it down mechanically if one is wanted — check
that it fails against the broken comparison before trusting it, since a test
written against the working version can pass for the wrong reason.

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

**Prevention.** The first half is fixed, the second cannot be.

1. **Fixed.** `ci.yml` now triggers on every pull request rather than only
   those targeting `main`, so an intermediate PR in a chain is checked
   rather than reporting "no checks reported".
2. **Not available on this repository.** Requiring a green check before
   merging needs branch protection or a ruleset, and GitHub offers neither
   for a private repository on a free organisation plan — the API answers
   `Upgrade to GitHub Pro or make this repository public`. Both developers
   also hold `write` rather than `admin`, so it is not ours to set even if
   the plan allowed it.

   Until the organisation upgrades or the repository goes public, a red
   check is advisory. Run the gate locally before pushing:

   ```bash
   docker compose exec app ./vendor/bin/pint --test
   docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
   docker compose exec app ./vendor/bin/pest
   ```

---

## A scripted rewrite reports success and leaves broken PHP

**Symptom.** A `perl -pi`, `sed -i`, or Python rewrite over a PHP file exits
zero and prints nothing. The file is syntactically broken — a closure signature
truncated mid-parameter, a namespace merged into the line above it, or CRLF
introduced into a file the rest of the repository keeps as LF. Nothing reports
it until a test run minutes later, by which point the failure looks like a bug
in the code the edit was aimed at.

Observed four times across the catalogue and cart slices. The last was a
two-substitution `perl -0pi -e` replacing a `DB::transaction(function () use
(...)` opening: the first substitution matched, the second matched a line it
was not meant to, and the result was
`syntax error, unexpected token ","` on the closure's parameter list.

**Cause.** A stream editor has no model of PHP. A pattern written against the
file as remembered rather than as it currently reads will match a different
span, match twice, or match nothing — and all three exit zero. Multi-line
patterns with `-0` are the worst case, because the span they can silently
swallow is unbounded.

**Fix.** `php -l` on the file, immediately — not at the next test run:

```bash
docker compose exec app php -l app/Actions/Cart/MergeGuestCart.php
```

Restore from a copy taken before the edit. `cp` before, `php -l` after.

**Why it recurs.** Every other tool in this workflow reports its own failures.
A stream editor's success and its no-op are the same exit code, and its
mangling is also that exit code.

**Prevention.** Use the editor tooling for edits to PHP, which fails loudly on
a non-matching target. Where a scripted rewrite is genuinely the right tool —
a repeated mechanical mutation across several files, as in a guard-deletion
sweep — pair it with `cp` to a scratch copy beforehand and `php -l` on every
touched file immediately after, in the same command, so the check cannot be
skipped.

---

## A guard-deletion test stays green because a second guard subsumes it

**Symptom.** A validation guard is deleted to prove its test is real, and the
test stays green. Unlike the authorization case, no second Action is involved
and no composition is hiding the source — the Action has one call site and the
assertion names the right exception class.

**Cause.** Two guards in the same Action raise the *same* exception class, and
a database constraint makes the second one's range cover the first's. In
`AddToCart` and `UpdateCartItemQuantity` the `$quantity < 1` guard and the
`min_order_quantity` guard both raise `InvalidCartQuantityException`, and
`chk_products_min_order_quantity_positive` keeps every minimum at 1 or more —
so on an empty cart line every quantity the first guard rejects, the second
rejects too. `expect(...)->toThrow(InvalidCartQuantityException::class)` cannot
tell them apart.

**Fix.** Two changes, both needed:

1. Assert the message, not only the class — Pest's `toThrow()` takes a second
   argument matched against the message, and the two guards have different
   wording because they are different messages to a customer.
2. Add the case only the guard under test can catch. For `AddToCart` that is a
   negative quantity against an existing line: `-2` added to a line of `5` sums
   to `3`, which clears both the minimum and the stock check, so without the
   guard "add to cart" silently removes two units.

**Why it recurs.** Two guards raising one exception class is good design — the
caller handles one failure mode — and the subsumption is a property of a
`CHECK` constraint in a migration, not of either guard. Nothing at the call
site or in the test hints that one range contains the other.

**Prevention.** When 1 Action raises the same exception class from more than
one place, the tests for those places assert the message. A shared exception
class with distinct static factories (`notPositive()`,
`belowMinimumOrder()`) is the signal to check for.

---

## PHPStan crashes at the memory limit and reports "Found 1 error"

**Symptom.** `./vendor/bin/phpstan analyse` ends with `Found 1 error` and
`Result is incomplete because of severe errors`. The single error is
`Child process error: PHPStan process crashed because it reached configured
PHP memory limit: 128M ... while running parallel worker`.

**Cause.** The container's CLI `memory_limit` is 128M and PHPStan's parallel
workers exceed it as the analysed codebase grows. It is a harness failure
reported in the same shape as an analysis finding.

**Fix.**

```bash
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
```

**Why it recurs.** `README.md` and `CLAUDE.md` both give the bare
`phpstan analyse` form, which worked until the codebase crossed the threshold
and will keep appearing to work on smaller checkouts.

**Prevention.** Pass `--memory-limit=1G` always. Setting `memory_limit` in
`phpstan.neon` would remove the flag from every call site and is the better
fix if this recurs on CI.

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

## Larastan reports an enum comparison as always false, on a property that really is that enum

**Symptom.** `phpstan analyse` fails on a `===` comparison against a
backed-enum-cast property — `Strict comparison ... will always evaluate to
false` — even though `php artisan tinker` confirms the property really does
cast to that enum at runtime, and the comparison is correct.

**Cause.** Larastan's model-property inference (`checkModelProperties: true`)
reads the column's raw database type for a MySQL `enum(...)` column and
infers a union of string literals (`'percentage'|'fixed'`) for the property,
rather than reading the `casts()` method. Every model in this codebase types
`casts()`'s return as `@return array<string, string>`, which is accurate for
scalar casts but tells Larastan nothing about which enum class a given key
casts to — so for a property backed by a DB-level `enum` column *and* cast to
a PHP backed enum, Larastan's two inference paths disagree, and the DB-driven
one wins. `Coupon::$type`/`$scope` were the first case: no earlier merged
Action compared a `Coupon`, `Order`, or `Payment` enum-cast property with
`===` against an enum case, so the mismatch was latent since the schema
migrations, not introduced by the code that first tripped it.

**Fix.** Add explicit `@property` docblock annotations naming the enum class,
above the model's class declaration:

```php
/**
 * @property CouponType $type
 * @property CouponScope $scope
 */
class Coupon extends Model
```

This is the standard Laravel/Larastan convention for typing magic properties,
and it takes priority over both the DB-column inference and `casts()`'s
generic return type.

**Why it recurs.** Any model with a MySQL `enum(...)` column cast to a PHP
backed enum is affected the moment code compares that property with `===`
against an enum case — `Order::$status`/`$payment_status`/`$payment_method`
are the next likely case, once `TransitionOrderStatus` is built and compares
against `OrderStatus`.

**Prevention.** When adding the first `===` comparison against a new
enum-cast property, run Larastan on the touched files before assuming the
comparison is fine — the runtime cast being correct (confirmed via tinker)
does not mean Larastan agrees. Add the `@property` annotation to the model at
the same time, rather than reaching for `treatPhpDocTypesAsCertain: false` in
`phpstan.neon`, which would silence this class of check project-wide instead
of fixing the one model's missing type information.

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

---

## A background test run fails after being "stopped," and a later `docker exec` path silently resolves to Windows

**Symptom.** Two unrelated-looking failures on a Windows host running Docker
through WSL2/Docker Desktop, both from the same underlying cause and both
capable of wasting real time chasing a phantom code defect:

1. A test run backgrounded through the harness is stopped, a database reset
   is done, and the *next* run still fails — sometimes with a plain
   assertion failure in an unrelated seeder, sometimes with `SQLSTATE[40001]:
   Serialization failure: 1213 Deadlock found`, sometimes with `SQLSTATE
   [HY000]: General error: 1412 Table definition has changed, please retry
   transaction` — and the specific error changes between re-runs of the exact
   same command.
2. `docker compose exec app cat /tmp/some-file.txt` (or any command
   referencing an absolute Unix path as an argument, not a heredoc) fails with
   `cat: 'C:/Users/.../AppData/Local/Temp/some-file.txt': No such file or
   directory` — a path that was never on the host at all.

**Cause.** Two separate mechanisms, easy to mistake for one bug:

For (1): stopping a background task by its harness-assigned ID kills the
*shell wrapper* the command was launched under, not necessarily every child
process it spawned. `sh -c "./vendor/bin/pest --coverage > out.txt; tail out.txt"`
spawns `pest` as a child of the `sh -c` process; killing the wrapper does not
guarantee the child dies with it. The orphaned `pest` process keeps running
inside the container — invisible to the harness, which believes it stopped
the task — and races every subsequent command against the same MySQL
container. The failure signatures above (`1213`, `1412`, an assertion that
should already be true) are exactly what two independent transactions
fighting over the same tables and a mid-flight `migrate:fresh` look like,
and they change between runs because the race is non-deterministic. `ps`
inside the container's PID namespace does not show it either if queried at
the wrong moment relative to `docker compose top`, which reports host-side
PIDs — `docker compose top app` is the reliable check; `ps aux` inside the
container frequently is not installed at all on this image.

For (2): Git Bash (MSYS2) rewrites any argument that looks like a POSIX
absolute path — `/tmp/...`, `/var/...` — into its Windows equivalent *before*
handing it to the program being run, including arguments meant for a command
running inside a Linux container that has never heard of `C:\`. `docker
compose exec app cat /tmp/coverage-run.txt` becomes, by the time Docker sees
it, a request for a file at a Windows path that does not exist inside the
container's filesystem at all — the container itself is unaffected and the
file is exactly where it should be.

**Fix.** For (1): after stopping a background task, verify the container is
actually idle before trusting the next result — `docker compose top app`
should show only the long-lived `php-fpm`/`boost:mcp` processes, nothing
matching the command just "stopped." If a stray process is still listed,
`docker compose exec app php -r 'posix_kill(<pid>, 9);'` (`kill` is not on
`$PATH` on this image); re-check `docker compose top` afterward, since the
PID `docker compose top` reports is the host-side one and may not be visible
or killable from inside the container's own PID namespace via a plain `kill`
call — `posix_kill` from PHP running as root in an `exec` does reach it. Once
confirmed idle, reset (`migrate:fresh --seed`) before trusting any test run
that follows.

For (2): prefix the command with `MSYS_NO_PATHCONV=1` —
`MSYS_NO_PATHCONV=1 docker compose exec -T app cat /tmp/coverage-run.txt`
disables the rewrite for that invocation. Heredocs and `sh -c "..."` strings
passed as a single quoted argument are not affected, since MSYS only rewrites
argv entries that look like standalone paths — the bug is specific to a bare
path as its own argument.

**Why it recurs.** Both are invisible from the output alone. (1) produces
error messages that look exactly like the kind of environment/schema bug
`troubleshooting.md`'s other entries describe, so the instinct is to debug
the code under test rather than check for a second live process — burning a
full clean-reset-and-rerun cycle (minutes, on this suite) before the real
cause is even suspected. (2) fails with a Linux-shaped error message
(`cat: ... No such file or directory`) that gives no hint the path was ever
rewritten, so it reads as "the file doesn't exist" rather than "the path was
translated" — the Windows path in the error is the only tell, and it is easy
to skim past.

**Prevention.** Treat "stopped" as a claim to verify, not a fact, for any
background task that runs inside a container — `docker compose top app`
before trusting the next result against that container, every time,
not just after an unusual-looking failure. For any `docker compose exec`
argument that is a bare absolute path rather than a quoted string or
heredoc, reach for `MSYS_NO_PATHCONV=1` by default on this host rather than
after the first confusing "No such file" error.

---

## Tailwind silently stops compiling new classes, and the page looks half-styled

**Symptom.** The storefront renders with *some* styling: colours and base
utilities land, but icons appear at their natural SVG size (a 16px chevron
drawn 250px tall), a responsive grid never leaves one column, and no hover
or entrance state fires. It reads as "the UI is bugged" rather than "no CSS
loaded", because plenty of CSS did load.

The tell: two classes in the *same* attribute behave differently.
`grid-cols-2` renders and `lg:grid-cols-3` beside it does not.

**Cause.** Tailwind 4 anchors automatic source detection at the **git root**.
This repository keeps `.git` one level above `online-store/`, and
`docker-compose.yml` mounts only `./online-store` into the container — so
from inside, there is no git root to find and automatic detection collapses
to whatever `@source` lines exist.

`resources/css/app.css` shipped with two, from the Laravel starter kit:

```css
@source '.../Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';   /* the compiled Blade cache */
```

Neither covers `resources/views`. Tailwind was reading **compiled Blade
output** rather than Blade source, so a class only existed in the stylesheet
if some page carrying it had already been rendered *and* the cache had not
been cleared since. Running `php artisan view:clear` — reasonable for any
number of unrelated reasons — empties the only source Tailwind is reading
and breaks classes that worked a minute earlier.

**Fix.** Scan the source, not the build artifact:

```css
@source '../views/**/*.blade.php';
@source '../../app/Livewire/**/*.php';
```

The explicit glob matters; the bare directory form `@source '../views'` did
not match here.

**Why it recurs.** The failure is partial, which sends you looking at the
markup. Every instinct says "a class is wrong" when the class is fine and
was never compiled. It also comes back on its own: any future
`view:clear`, or a new component whose page has not been rendered yet,
reproduces it exactly.

**Prevention.** When a utility appears not to apply, check whether it is in
the compiled stylesheet before touching the template:

```bash
curl -s "http://localhost:5173/resources/css/app.css" | grep -c 'grid-cols-3'
```

Zero means a source-scanning problem, not a markup problem. Note that in
dev Vite serves CSS **wrapped in a JS module** — the whole sheet is one
line with `\n` escapes and `\:` for escaped colons, so line-oriented
counting (`grep -c '@media'`) reports 1 no matter what is in it. Count
substrings, not lines.

---

## Vite serves assets the browser cannot reach, and the page renders unstyled

**Symptom.** `/catalogue` returns 200 with correct HTML and no PHP error, but
no CSS or JS applies. `curl` against the Vite port succeeds, so the dev
server is plainly running.

**Cause.** `public/hot` contained `http://0.0.0.0:5173`. That is a bind-all
address: meaningful to a listening socket inside the container, meaningless
to a browser. Every asset request failed, and because the failures are
network-level the page renders as bare HTML with nothing in the PHP log.

**Fix.** Tell the plugin what the *browser* should use, separately from what
the server binds to — `online-store/vite.config.js`:

```js
server: {
    host: '0.0.0.0',          // bind inside the container
    origin: 'http://localhost:5173',  // what goes into public/hot
    hmr: { host: 'localhost' },
},
```

**Why it recurs.** `curl http://localhost:5173/resources/css/app.css` returns
200 from the host, which looks like proof the assets are fine — but the host
and the browser resolve `0.0.0.0` differently from the container. Any fresh
clone, or anyone who deletes `public/hot`, gets it again.

**Prevention.** Check what `@vite` actually emitted rather than whether the
port answers:

```bash
cat online-store/public/hot          # must read http://localhost:5173
curl -s http://localhost:8080/catalogue | grep -o 'src="http://[^"]*5173[^"]*"'
```

---

## The webfont never loads, and every heading falls back to the system font

**Symptom.** Type looks generic and slightly wrong — weights are close but
letterforms are not the ones the design assumes. No error anywhere, and
`public/fonts-manifest.dev.json` exists with correct `@font-face` rules.

**Cause.** `Vite::fonts()` is a separate call. `@vite(['…css', '…js'])` does
**not** inject the font manifest, so the `laravel-vite-plugin/fonts` output is
generated and then never referenced.

**Fix.** In the layout `<head>`, alongside `@vite`:

```blade
{{ Vite::fonts() }}
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

**Why it recurs.** The manifest existing is the misleading part: the fonts
pipeline looks configured and working, because the half that generates
output is. Nothing warns that nothing consumes it, and a fallback font is
legible enough that the page does not look broken — only slightly off.

**Prevention.** Grep the rendered head, not the manifest:

```bash
curl -s http://localhost:8080/catalogue | grep -c '@font-face'
```

Zero means it is not wired, regardless of what is in `public/`.

## Every route 500s with `tempnam(): file created in the system's temporary directory`

**Symptom.** Every page — storefront and `/admin` alike — returns 500 with
`tempnam(): file created in the system's temporary directory`. Nothing is
written to `storage/logs/laravel.log`; the file does not even exist. The
stack trace runs through `Illuminate\View\Compilers\BladeCompiler:199` into
`Illuminate\Filesystem\Filesystem:222`. `df` shows plenty of free space and
inodes, `/tmp` is `1777`, and `ls -ld storage` from `docker compose exec`
looks fine — `drwxrwxr-x`, owner `1000`.

**Cause.** `Filesystem::replace()` calls `tempnam()` against the *target*
directory, not the system temp dir, so the message names `/tmp` while the
directory actually refused is `storage/framework/views`. `docker compose
exec` runs as **root**, which can write anywhere and makes the permissions
look correct; PHP-FPM's request workers drop to **`www-data` (uid 33)**,
per the pool config the image ships. The bind-mounted `online-store/` keeps
its host ownership — uid 1000, mode `drwxrwxr-x` — so `www-data` is neither
the owner nor in the owning group, and has no write bit.

This appears after anything that recreates `storage/` with host ownership:
a fresh clone, a restore from backup, or a machine migration where files
arrive owned by the host user.

**Fix.** Give the group to `www-data` and make it writable, with setgid so
newly created files inherit the group rather than reintroducing the problem:

```bash
docker compose exec app sh -c '
chgrp -R www-data storage bootstrap/cache
chmod -R g+w storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} \;
'
```

Verify as the user that actually serves requests, not as root:

```bash
docker compose exec app su -s /bin/sh www-data -c \
  'touch /var/www/html/storage/framework/views/__probe && echo WRITABLE'
```

**Why it recurs.** Three things each hide it independently. The error names
`/tmp`, which is world-writable and therefore the first thing ruled out.
`docker compose exec` runs as root, so every manual permission check passes
while every real request fails. And Laravel cannot log the failure — the log
write needs the same `storage/` it has just been denied — so
`storage/logs/laravel.log` is absent rather than informative, which reads
like "logging is misconfigured" instead of "storage is unwritable."

**Prevention.** When a permission error is suspected inside this container,
check as `www-data` (`su -s /bin/sh www-data -c '…'`), never from the
default root shell — the root shell cannot reproduce the failure by
construction. Treat an absent `laravel.log` on a 500 as evidence about
`storage/` itself rather than about logging config.

## A Filament TableWidget with a grouped query fails `only_full_group_by`

**Symptom.** A `TableWidget`'s custom `->query()` — a `GROUP BY` aggregate
over a model that isn't itself the primary subject, e.g. best-sellers summed
from `order_items` — throws `SQLSTATE[42000]: ... 1055 Expression #N of
ORDER BY clause is not in GROUP BY clause and contains nonaggregated column
'...id' which is not functionally dependent on columns in GROUP BY clause;
this is incompatible with sql_mode=only_full_group_by`. The query looks
correct read on its own — every selected column is either grouped or
aggregated — and the error names a column (`id`) that was never written in
the query at all.

**Cause.** Filament appends its own `ORDER BY <primary key>` as a
stable-sort tiebreaker after whatever ordering the query or a sortable
column applies (`Filament\Tables\Concerns\HasRecords`'s
`applyDefaultSortToTableQuery`, gated by `hasDefaultKeySort()` — true by
default). That extra `ORDER BY` is what MySQL is complaining about, not
anything hand-written: the widget's own `MIN(order_items.id) as id` alias
satisfies Eloquent's hydration, but the appended `ORDER BY` targets the raw
qualified column `order_items.id`, which is genuinely absent from the
`GROUP BY` clause. `only_full_group_by` is MySQL's default mode and ADR-0005
is explicit the suite runs against real MySQL specifically so constraints
like this stay live rather than passing silently under SQLite.

**Fix.** Turn off the appended tiebreaker on a widget whose query is already
fully ordered by its own aggregate:

```php
return $table
    ->query(/* ... grouped, already ->orderByDesc(...) ... */)
    ->defaultKeySort(false)
    ->columns([...]);
```

**Why it recurs.** The query is correct by every check that runs before a
real request: `php -l`, Larastan, and reading the SQL by eye. Nothing in
the widget class mentions `order_items.id` in an `ORDER BY` — Filament adds
it after the fact, from a method most of the codebase never has reason to
call directly. `CLAUDE.md`'s "verify against a running app, not by reading
code" is this exact case: a table widget test with real factory-created
rows (not an empty table) is what surfaced it, and an empty table would not
have — MySQL only enforces `only_full_group_by` once a query actually
executes against rows to order.

**Prevention.** Any custom `TableWidget` or Filament `Table` query that
`GROUP BY`s on something other than the model's own primary key should
default to `->defaultKeySort(false)` and supply its own `orderBy`/
`orderByDesc` explicitly — do not rely on Filament's implicit key sort to
break the tie for an aggregate row that has no single natural primary key.

## A Filament field saves as `null` no matter what is typed into it, and editing an unrelated field erases it

**Symptom.** A product's weight and all three dimensions are `null` in the
database however carefully they are typed into the admin panel. Worse: a
product that *did* have a weight (from a seeder or a fixture) loses it the
first time anyone opens its edit page and saves — even after changing only
the name. Nothing errors, no validation message appears, and the form looks
correct on screen the whole time.

**Cause.** `ProductForm`'s measurement inputs are named `weight_input`,
`length_input`, `width_input`, `height_input` — deliberately not the
canonical `weight_g`/`*_mm` columns, because the admin types in whatever
unit suits and `App\Filament\Concerns\ConvertsMeasurementInput` converts.
They carried `->dehydrated(false)`, whose stated intent was "these are not
columns, so they must never reach the model."

`dehydrated(false)` does not mean that. It excludes the field from the
form's submitted state *entirely* — so the value never reached
`handleRecordCreation()`/`handleRecordUpdate()` at all, and the trait that
exists solely to read those keys found nothing. `convertMeasurements()`
then correctly interpreted "absent" as "unspecified" and wrote `null`,
which is right for a blank field and catastrophic for a field whose value
was stripped in transit.

The trait already `unset()`s the four `*_input` keys itself before handing
the array to Eloquent. `dehydrated(false)` was therefore both redundant
(the trait already prevented the non-column reaching the model) and fatal
(it prevented the value reaching the trait).

**Fix.** Drop `->dehydrated(false)` from the `*_input` fields — the trait's
own `unset()` is what keeps them off the model. Separately, hydrate them on
edit: they are not columns, so Filament's default record-attribute fill
cannot reach them, and a blank field on open is what turned a save into an
erase. `ConvertsMeasurementInput::hydrateMeasurementInput()` is the inverse
of `convertMeasurements()` and is called from
`EditProduct::mutateFormDataBeforeFill()` and the variations relation
manager's `EditAction::fillForm()`.

**Why it recurs.** Nothing catches it. Pint, Larastan, and every existing
test passed for the entire life of the bug: the form definition reads
correctly, the trait is well-formed and unit-testable in isolation, and no
test asserted the round trip through a real Filament page. The two halves
were individually right and never connected. It also hides behind its own
symmetry — create silently dropping a value and edit silently erasing one
look like two unrelated bugs, so neither points at the single shared cause.

**Prevention.** `dehydrated(false)` means "do not submit this field," not
"do not persist this column." Reach for it only for genuinely display-only
fields that no server-side code reads. When a form field feeds a
transformation before hitting the model, it must stay dehydrated (the
default) and the transformer is responsible for removing it — and any such
field must be hydrated back on edit, or every save silently overwrites with
the blank. Assert the round trip against a real page (`Livewire::test(
EditPage::class)` → `fillForm` one unrelated field → `call('save')` →
expect the untouched columns unchanged), not the form definition.

## A test that overrides one factory field intermittently fails, though the code it tests is correct

**Symptom.** A test that pins `discount_price` (or another field a factory
computes *from* a sibling field) fails roughly one run in five to ten, with
either a wrong value asserted or a `QueryException` on a `CHECK` constraint
the test never appeared to touch. Re-running it usually passes. The failure
looks like it is testing something real — a resolver picking the wrong
variation, a discount not applying — and the code under test is, in fact,
correct.

**Cause.** `ProductFactory` and `ProductVariationFactory` both compute two or
three related fields from one internal random draw — a random base price,
then a discount derived as a fraction of *that same* price, then (on
`ProductFactory`) a window (`discount_starts_at`/`discount_ends_at`) derived
from a *different* random draw, independent of whether a discount exists at
all. Overriding **one** of a related group in a `->create([...])` call does
not touch the others: they were already computed inside `definition()`
before Laravel merges the override in.

Two distinct failure shapes came from this, both traced live rather than
assumed from reading the factories:

- Overriding `discount_price => null` on a `Product` leaves
  `discount_starts_at`/`discount_ends_at` at whatever random dates the
  factory drew independently (`fake()->boolean(30)` decides whether a window
  exists at all, `fake()->dateTimeBetween('-2 months', '+1 month')` decides
  where). A variation-level discount is still gated by the *product's*
  window per §11 (`ResolveVariationPrice`/`ResolveProductPrice::windowActive()`),
  so roughly 30% of runs draw a real window, and among those, some fraction
  land on a window that is closed right now — silently suppressing a
  discount the test explicitly set at the variation level.
- Overriding `price` on a `ProductVariation` without also pinning
  `discount_price` leaves the factory's own `discount_price` computed
  against its *internal*, still-random `$price` variable — which can now
  exceed the overridden price, tripping
  `chk_product_variations_discount_below_price` as an uncaught
  `QueryException` at insert time, before the test's own assertions ever run.

**Fix.** Whenever a test overrides `discount_price`, `price`, or
`regular_price` on a `Product` or `ProductVariation` factory call, override
every field the factory derives from the same random draw in the same call —
`discount_starts_at`/`discount_ends_at` alongside `discount_price` on a
product, and `discount_price` alongside `price` on a variation, even when
the intent is "no discount" (pin it to `null` explicitly rather than leaving
it to the factory).

**Why it recurs.** The failure rate is low enough (roughly one run in five to
ten per affected assertion) that a single local run, or a single CI run,
usually passes — which is exactly the shape that lets a flaky test merge
looking green and then fail unpredictably later, on an unrelated PR, for a
reason nobody touched. `ProductListPriceAndRatingFilterTest.php`'s own
existing comment already named the `discount_price`-alone half of this for
`regular_price` filtering; it did not extend to the window dates because
that test's own assertions never depended on a discount being active.

**Prevention.** Before trusting a new test that touches money or a discount
window, run it standalone at least ten times in a loop, not once — a test
that has been observed failing zero times is not evidence it cannot. Prefer
pinning every field in a factory's own randomised group explicitly over
trusting that overriding one is enough; when in doubt, read the factory's
`definition()` for what else is derived from the field being overridden.

## `Auth::logoutOtherDevices()` is called, and other sessions stay signed in

**Symptom.** `ChangePassword` requires `current_password`, changes the
password, and calls `Auth::logoutOtherDevices()`. The password does change.
Every other browser signed in as that user keeps working — the whole reason
`current_password` is required is that a password change is the standard
response to "someone else may be signed in as me", and that half silently
did not happen. Nothing errors, Larastan is green, Pint is green, and the
code reads exactly as intended.

**Cause.** `Auth::logoutOtherDevices()` only does anything when
`Illuminate\Session\Middleware\AuthenticateSession` is in the request's
middleware stack. That middleware is what stores a password hash on the
session and compares it on each subsequent request; without it, the call
rewrites the user's hash and nothing ever compares another session against
it. It is **not** in Laravel's default `web` group — it has to be added.

What made this hard to see here: Filament *does* register it, in
`AdminPanelProvider`'s own `->middleware()` list, which the Filament
installer scaffolds. So the panel had the protection and the storefront did
not, and a grep for `AuthenticateSession` finds a hit — in a file that has
nothing to do with the storefront. The natural conclusion from that hit is
"it is registered", which is true and irrelevant.

**Fix.** Append it to the `web` group in `bootstrap/app.php`, after
`StartSession`:

```php
$middleware->web(append: [
    AuthenticateSession::class,
    EnsureAccountIsActive::class,
]);
```

`AuthenticateSession` also logs the *acting* session out unless the password
change re-issues it — `ChangePassword` already calls `session()->regenerate()`
immediately after, which is what keeps the user signed in on the browser they
just used.

**Why it recurs.** It is a security guarantee whose absence looks identical
to its presence from every angle except an actual second session: no error,
no failing test unless one is written for it, and a docblock nearby
asserting the guarantee holds. This project's own `ChangePassword` carried
the sentence "Every other session for this user is invalidated" while it was
false. A comment stating intent is not evidence of behaviour, and a
framework call being present is not evidence its precondition is met.

The same shape applies one layer up: `Login` checks `is_active` as part of
the credentials, which is a check at one instant, and nothing re-checked it
afterwards — a customer deactivated or soft-deleted mid-session kept
browsing until the session expired on its own. `canAccessPanel()` already
stated that reasoning for the panel ("a session outlives the row it
authenticated against"); the storefront had no equivalent until
`EnsureAccountIsActive`.

**Prevention.** For any auth rule, ask what re-checks it on the *next*
request, not what checked it at sign-in. When a framework method is called
for its side effect, check its precondition is registered in the stack that
actually serves the route — `php artisan tinker` printing
`app('router')->getMiddlewareGroups()['web']` answers this directly, and is
what confirmed both halves here. `AuthSessionInvalidationTest` pins the
group's contents for this reason; `docs/reference/ui-tests.md` records what
each of its cases proves.
