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

---

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

**Fix.** Not yet fixed — tracked in the changelog's `Open` section.

**Why it recurs.** It is invisible to anyone whose database predates the
problem, which is everyone who has been working on the project.

**Prevention.** Reference data the application needs in order to work at all
belongs in `DatabaseSeeder`, not in a developer's database. Test setup changes
against a genuinely fresh database rather than an existing one.

---

## A seeded column silently does nothing

**Symptom.** A seeder sets a field and the resulting row does not have it. No
error.

**Cause.** Eloquent discards attributes that are not mass-assignable, or that do
not exist, without complaint. `DatabaseSeeder` passes `name` to a users table
whose columns are `first_name` and `last_name`.

**Why it recurs.** The seeder succeeds. Nothing distinguishes a discarded
attribute from an accepted one.

**Prevention.** `Model::preventSilentlyDiscardingAttributes()` in
`AppServiceProvider::boot()`, guarded to non-production, turns this into an
exception. Not yet enabled.
