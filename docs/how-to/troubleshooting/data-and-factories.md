# Troubleshooting — data, factories, and seeding

Part of the [troubleshooting index](../troubleshooting.md). Errors where a
factory, seeder, or fixture writes data that looks right until something
reads it back — a Blueprint regeneration, an insert, or a second test run.

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
wrote a slug past its `varchar(60)` and the test passed every time. See
[database-and-migrations.md](database-and-migrations.md) for what SQLite does
not enforce.

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
