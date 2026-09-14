# Troubleshooting — Filament and the admin panel

Part of the [troubleshooting index](../troubleshooting.md). Errors specific
to Filament resources, forms, and the admin panel shell.

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
red squiggle is indistinguishable from the vendor-not-on-host noise in
[ide-and-static-analysis.md](ide-and-static-analysis.md), so it's easy to
dismiss as the same false alarm.

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
grep -rn "CreateAction\|DeleteAction" src/app/Filament/Resources/<Resource>/
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

---

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

---

## `->telRegex(null)` on a Filament `TextInput` does not disable the phone format check

**Symptom.** A `TextInput::make('phone')->tel()->telRegex(null)` field still
rejects a real phone number with "The phone field format is invalid" — but
only sometimes. Locally, with a fixed test value, it can pass every time.
Under CI, or with a factory-randomised seed, it fails intermittently: some
generated numbers pass Filament's default pattern, some don't.

**Cause.** `TextInput::tel()` wires up validation with:

```php
$this->regex(static fn (TextInput $component) => $component->evaluate($condition)
    ? $component->getTelRegex()
    : null);
```

and `getTelRegex()` is:

```php
return $this->evaluate($this->telRegex) ?? '/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/';
```

`telRegex(null)` sets `$this->telRegex` to `null`. `evaluate(null)` returns
`null`. The `??` then falls through to Filament's own default pattern
regardless — `telRegex(null)` and never calling `telRegex()` at all produce
the identical result. There is no way to pass `telRegex()` a value that
disables the check; the method only ever *replaces* the pattern, never
removes it.

**Fix.** Call `->regex(null)` directly, **after** `->tel()` in the chain:

```php
TextInput::make('phone')
    ->tel()
    ->regex(null)
    ->maxLength(30)
    ->nullable(),
```

`regex()` stores whatever it is given with no fallback
(`CanBeValidated::regex()`), and a later call in the method chain overwrites
the closure `tel()` set. `getRegexPattern()` then evaluates to `null`, and
`filled(null)` is `false`, so no `regex:` rule is added to the validation
array at all. `->tel()`'s only other effect is the HTML `type="tel"`
attribute, which is unaffected.

**Why it recurs.** The bug is invisible in the common case: most real phone
numbers do match Filament's default pattern (`+`, digits, spaces, dashes,
dots, parens), so a field that "looks disabled" because it accepts every
number a developer tries by hand is not actually disabled — it is coincidence.
It surfaces only when a value outside that pattern reaches the field, which
here was a Faker-generated phone number on a randomised test run rather than
a hand-typed one. A single local run with one fixed value proves nothing
about this; the failure needs an adversarial or randomised input to appear,
which is exactly what CI's re-seeded factory data provides and a developer's
own manual click-through does not.

**Prevention.** Never trust `X(null)` to mean "no rule" for a Filament
validation helper without checking whether the underlying method has a `??`
fallback — grep the vendor source for the setter (`telRegex`, here) before
assuming a null argument is a no-op. When a field's format constraint is
meant to come from the schema and nothing else, prefer clearing the concrete
rule (`->regex(null)`) over an indirect setter, and prove it by running the
suite enough times (or with different Faker seeds) for a randomised value to
actually exercise the path — a single green run after the "fix" is not
evidence, per CLAUDE.md's own testing discipline.
