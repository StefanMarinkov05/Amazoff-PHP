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

**Fix.** Keep concurrency tests out of `RefreshDatabase`. `tests/Concurrency/`
is registered as its own suite in `phpunit.xml` and is excluded from the
`->use(RefreshDatabase::class)` binding in `tests/Pest.php`. Those tests commit
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
Grant the actor exactly one permission short of success rather than granting
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
`ReserveStockConcurrencyTest` and `PublishProductConcurrencyTest` are the two
worked examples; the second differs in asserting the winner *count*, which is
possible only because no `CHECK` constraint backs that invariant up.

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
