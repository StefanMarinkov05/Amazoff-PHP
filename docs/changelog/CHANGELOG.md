# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). Dates are
when the work happened, not when it was committed — nothing in
`Unreleased` has a git commit yet.

## Unreleased

### Added

- Docker Compose local dev environment: `app` (PHP-FPM), `webserver`
  (nginx), `db` (MySQL 8), `vite`, `mailpit`.
- Filament admin panel, installed and verified against Laravel 13.8.
- `spatie/laravel-permission` for roles. `User` uses `HasRoles`;
  `canAccessPanel()` gates the Filament panel on `User::STAFF_ROLES`.
  Verified: staff reach the panel, customers do not, multi-role works.
- `spatie/laravel-activitylog` and `astrotomic/laravel-translatable`
  installed — not wired in yet, decisions behind them still open.
- Saloon, Stripe SDK, Purify, Larastan, Pest, `laravel-lang/common` added
  to `composer.json`.
- GitHub Actions CI workflow: Pint, Larastan, Pest against a real MySQL
  service container, triggered on push/PR to `main`.
- `docs/` restructured around Diátaxis, plus `adr/` and `changelog/`.
- `docs/adr/0001-tech-stack-selection.md` — combined ADR for kickoff-phase
  tech choices.
- `docs/explanation/documentation-design.md` — how `docs/` is organized and
  the ADR vs explanation split.
- `docs/explanation/gdpr.md` — soft vs hard delete, order anonymization,
  hashed coupon redemption identifiers.
- `docs/how-to/regenerate-with-blueprint.md` — safe regeneration procedure,
  the two hand-written files, and the generated code that needs correcting.
- `docs/how-to/use-ci.md` — what the workflow runs, how to reproduce a
  failure locally, and what a green check does not cover.
- `CLAUDE.md`, `CONTRIBUTING.md`, `CONTRIBUTIONS.md`,
  `.github/PULL_REQUEST_TEMPLATE.md` at repo root.
- Schema generated from `draft.yaml`: 39 migrations, 32 models, 32
  factories. `migrate:fresh` applies cleanly; every factory persists a row.
- `online-store/stubs/blueprint/` — overrides `model.fillable.stub` and
  `model.hidden.stub` to emit `@var list<string>`, which Larastan requires.
- `App\Enums` — 12 backed enums covering all 19 `enum` columns in the schema:
  `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `ShipmentStatus`,
  `InventoryMovementType`, `ArticleStatus`, `CouponType`, `CouponScope`,
  `AddressType`, `DeliveryType`, `AttributeInputType`, `NewsletterStatus`.
  Each carries `values()` and implements Filament's `HasLabel`, so tables,
  filters, and select fields render them without a per-resource value map.
- `HasColor` on the seven enums where a badge colour carries meaning —
  `OrderStatus`, `PaymentStatus`, `PaymentMethod`, `ShipmentStatus`,
  `InventoryMovementType`, `ArticleStatus`, `NewsletterStatus`. The remaining
  five classify rather than describe state, where a colour would be decoration.
- `allowedTransitions()` and `canTransitionTo()` on the four lifecycle enums —
  `OrderStatus`, `PaymentStatus`, `ShipmentStatus`, `ArticleStatus`. Which
  enums get a matrix, why the other eight do not, and where the policy and
  enforcement halves belong are in ADR-0004. Nothing calls these yet.
- Enum casts on the 12 affected models, so the enums are authoritative in
  application code rather than decorative. Verified against MySQL: a status
  written as an enum case reads back as one.
- Factories use `Enum::cases()` in place of the literal value arrays Blueprint
  generated. A renamed case now fails at parse rather than on insert.
- `tests/Feature/FactoryTest.php` — persists one row from every factory,
  discovered by glob. This is the check `docs/how-to/regenerate-with-blueprint.md`
  describes as the only way to catch a factory writing a value its column
  cannot hold; it was documented but not automated.
- Six Filament resources over the catalogue's lookup entities: `Brand`,
  `Tag`, `ProductCategory`, `ArticleCategory`, `Attribute`, `AttributeValue`.
  Scaffolded with `make:filament-resource --generate`, then corrected by hand
  — see Fixed, below. `ProductCategory`'s self-referencing `parent_id` and
  `AttributeValue`'s `attribute_id` foreign key both resolved to `Select`
  fields backed by `relationship()` without manual intervention.
- `database/seeders/RoleSeeder.php` — creates the three `User::STAFF_ROLES`
  rows (`administrator`, `content_editor`, `warehouse_employee`). Runs in
  every environment, production included, since a role must exist before
  anyone can be assigned to it through the panel.
- `DatabaseSeeder` now creates a staff account and assigns it the
  `administrator` role, gated behind `! app()->isProduction()` — reference
  data (roles) seeds everywhere, a known-password test credential does not.
  Closes the gap the `Open` section below used to track.
- `User` implements `Filament\Models\Contracts\HasName`, alongside the
  existing `FilamentUser`. See Fixed, below, for why this was load-bearing
  rather than cosmetic.
- `docker/mysql/init/01-test-database.sh`, mounted into the `db` service's
  `docker-entrypoint-initdb.d/`. Creates `online_shop_test` and grants the
  app user access to it on first container initialization, matching what
  CI's MySQL service already provisions — local `pest` runs against a fresh
  clone without a manual `CREATE DATABASE` step.
- `database/seeders/PermissionSeeder.php` — 108 permissions named
  `{ability}_{resource}`, the ability half matching the Laravel policy method
  that checks it. Seeded in full rather than per built resource: §3.3 and
  §3.4 describe what a role may do, not what happens to be built, and
  `content_editor`'s deny-list is only meaningful if the permissions it
  excludes exist.
- `RoleSeeder` now attaches those permissions — 20 to `content_editor`, 12 to
  `warehouse_employee`, none to `administrator`. `syncPermissions()` rather
  than `givePermissionTo()`, so a permission removed from the seeder is
  actually revoked on the next run; the tradeoff is that a re-seed discards
  runtime edits made through the panel.
- `database/seeders/UserSeeder.php` — one account per role plus a plain
  customer, gated to non-production. §37 criterion 18 is only demonstrable
  with an account per role, and the customer is what proves
  `canAccessPanel()` denies someone holding no role at all.
- `Gate::before` in `AppServiceProvider` grants `administrator` every
  ability. Returns `null` rather than `false` when the role is absent, so
  other users still reach spatie's callback and then their policy.
- Seven Policy classes over the seven Filament Resources. Each method is one
  `$user->can('{ability}_{resource}')`, checking permissions rather than role
  names because §3.5 requires permissions editable at runtime.
- `tests/Feature/RolePermissionTest.php` — 32 tests over the §37 criterion 18
  matrix, weighted toward the denials, including that every model with a
  Resource resolves a policy at all.
- `docs/adr/0006-authorization-layers.md` — why panel access, permissions,
  policies, and the administrator exemption are four separate mechanisms, and
  what the arrangement costs.
- `docs/how-to/run-the-tests.md` — running one file or one test, the flags
  worth knowing, why the suite needs MySQL, and how to check that a test can
  actually fail.

### Changed

- Local database container runs with relaxed durability
  (`innodb_flush_log_at_trx_commit=2`, `sync_binlog=0`, `--skip-log-bin`).
  Production is unaffected — it runs on Forge with MySQL's defaults. A single
  `CREATE TABLE` + `DROP TABLE` inside the container went from 8.28s to 3.16s;
  `migrate:fresh` from 6m51s to seconds.
- `docker/php/Dockerfile` installs Oracle's `mysql-client` rather than Debian's
  `default-mysql-client`, which is MariaDB's and rejects the flags Laravel
  passes to `mysqldump`. `schema:dump` now works, which lets `migrate:fresh`
  load a schema dump instead of replaying every migration.

### Fixed

- `app/Models/User.php` was invalid PHP — an unclosed `$hidden` array and an
  unclosed `profile()` method from a merge conflict resolved by hand. It also
  referenced `Profile`, `Role`, and `Permission`, all removed in the
  `spatie/laravel-permission` switch, and redefined `roles()`, colliding with
  the `HasRoles` trait. Now hand-written and excluded from generation.
- Four factories contained empty class names (`use App\Models\;`,
  `::factory()`) and would not parse.
- 21 factories referenced columns that no longer existed after the schema
  revision. Blueprint does not overwrite existing files, so a second
  `blueprint:build` had layered new columns onto stale ones.
- `AddressFactory` and `OrderAddressFactory` generated `fake()->country()`
  into a `char(2)` column, failing with a truncation error on insert.
- `ProductCategoryFactory` set `'parent_id' => ProductCategory::factory()`
  on a self-referencing key, recursing without termination.
- `UserFactory` hashed a random password per row and left no known password
  for tests to log in with. Now hashes once per process, with an
  `unverified()` state.
- `make:filament-resource --generate` does not infer unique-index validation
  from the schema. All six generated forms had a `slug` field with no
  `->unique()` rule despite a database-level unique constraint on every one
  of them; `AttributeValueForm` needed a composite rule
  (`modifyRuleUsing`) to match `attribute_values`' `UNIQUE(attribute_id,
  slug)` rather than a plain column-level check. Corrected by hand in all
  six resources.
- `AttributeValueForm.php` imported `Filament\Forms\Get`, which does not
  exist in Filament v4 — `Get`/`Set` moved to
  `Filament\Schemas\Components\Utilities\Get`. Pint and the IDE (which
  cannot resolve any vendor class from the host — see `troubleshooting.md`)
  both missed it; Larastan caught it as `class.notFound`. Would otherwise
  have failed at runtime the first time the closure using it ran.
- `FilamentManager::getUserName()` threw a `TypeError` on every panel page
  after login. It falls back to reading a `name` attribute when the
  authenticated model does not implement `HasName`, and this schema has no
  `name` column — only `first_name`/`last_name`. Fixed by implementing
  `HasName::getFilamentName()` on `User`.
- `DatabaseSeeder` passed `'name' => 'Test User'` to a `users` table with no
  `name` column — silently discarded by Eloquent rather than erroring (see
  the seeded-column entry in `troubleshooting.md`). Replaced with a seeder
  that sets `first_name`/`last_name`, matching the actual schema.
- `public/css/filament` and `public/fonts/filament` existed as empty
  directories — the compiled assets were never published, so every asset
  request 404'd and the panel rendered unstyled. `php artisan
  filament:assets` now runs as part of setup; see `README.md`.

### Removed

- `App\Enums\Role`, `App\Models\UserRoleAssignment`, and the `user_roles`
  migration. The enum + pivot approach was built first, then replaced by
  `spatie/laravel-permission` — §3.5 requires runtime-editable permissions.

### Open

- PHP version: `composer.json` declares `^8.3`, the lockfile requires 8.4.
  Not pinned.
- Content translation storage shape and default locale.
- Audit log shape.
- Enum value lists exist in two places: the 19 `enum()` literals in the
  migrations, and `App\Enums`. The migrations are frozen by the append-only
  rule, so the duplication cannot be removed retroactively. Migrations added
  from here on should use `OrderStatus::values()` rather than a literal array,
  which keeps the copy generated instead of typed.
