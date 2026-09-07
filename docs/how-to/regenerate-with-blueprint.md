# How to regenerate models and factories with Blueprint

`src/draft.yaml` is the schema definition. `blueprint:build` turns
it into migrations, models, and factories.

Blueprint never overwrites an existing file. Running it twice after editing
`draft.yaml` layers new columns onto stale ones rather than replacing them,
which is how a previous run produced 21 factories referencing columns that
no longer existed and four containing empty class names (`use App\Models\;`).

## Regenerating models and factories

Migrations are append-only once merged, so they are excluded from the run:

```bash
# from the repo root
cd src
cp app/Models/User.php /tmp/User.php
cp database/factories/UserFactory.php /tmp/UserFactory.php
rm -f app/Models/*.php database/factories/*Factory.php
cd ..

docker compose exec app php artisan blueprint:build --only=models,factories

cp /tmp/User.php src/app/Models/User.php
cp /tmp/UserFactory.php src/database/factories/UserFactory.php
docker compose exec app ./vendor/bin/pint
```

Deleting first is what makes the result clean — without it Blueprint skips
every file that already exists and the stale definitions survive.

`--only=models,factories` keeps migrations out of the run. A bare
`blueprint:build` generates a second `create_*_table` migration for every
table with a fresh timestamp, and `migrate:fresh` then fails on the
duplicate.

## Files Blueprint must not own

Two files are hand-written and get restored after every regeneration:

| File | Why |
|---|---|
| `app/Models/User.php` | Blueprint generates a plain `Model`. The real one extends `Authenticatable`, implements `FilamentUser`, and uses `HasRoles` and `SoftDeletes`. |
| `database/factories/UserFactory.php` | Blueprint generates `fake()->password()`, which costs a bcrypt round per row and leaves no known password to log in with. |

Both carry a comment at the top saying so.

## Adding a new entity

1. Add the model to `draft.yaml`.
2. Run `blueprint:build` with no `--only` flag — the new table has no
   migration yet, so one is wanted.
3. Confirm only the new migration appeared; delete any duplicate for a table
   that already existed.
4. Restore the two hand-written files and run Pint.

## Custom stubs

`src/stubs/blueprint/` overrides two of Blueprint's templates.
Blueprint checks that directory first and falls back to its own for anything
not found there, so only the changed files live there.

`model.fillable.stub` and `model.hidden.stub` emit `@var list<string>`
instead of `@var array`. Larastan treats `array` as not covariant with
Laravel's own `list<string>` and reports an error on every generated model —
33 of them before this override existed. Fixing the stub keeps future
generations clean; fixing the 32 generated files by hand would not survive
the next run.

## Generated code that needs correcting afterwards

Blueprint maps column names to Faker methods without consulting column
types, and generates a factory reference for every foreign key regardless of
nullability. Two cases in this schema need a manual fix after generation:

**`country`** — the column is `char(2)`, Blueprint generates
`fake()->country()`, which returns a full country name and fails with a
truncation error. `AddressFactory` and `OrderAddressFactory` use
`fake()->countryCode()`.

**`ProductCategory.parent_id`** — a self-referencing key. Blueprint
generates `'parent_id' => ProductCategory::factory()`, so every category
creates a parent, which creates a parent, without termination. The factory
sets `null` and provides a `childOf()` state for building nested
categories.

## Verifying a regeneration

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest
docker compose exec app php artisan migrate:fresh --force
```

Pint, Larastan, and Pest catch syntax errors and type problems. They do not
catch a factory writing a value the column cannot hold — the `country` bug
above passes all three and fails only on insert. Creating one row from every
factory is what catches that class of problem.
